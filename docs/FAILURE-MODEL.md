# Failure Model

What this system guarantees, what it does not, and what it does when things
break. Read this before putting anything behind it that matters.

The short version: **production-inspired, not production-ready.** It is a
single-node runtime with a single point of failure and at-most-once
delivery. Everything below says exactly where the edges are.

---

## Delivery semantics: at-most-once, with an ambiguous window

A request is executed **at most once**. The pool never retries on its own,
and you should think hard before adding retries on top.

The reason is a window the runtime cannot see into:

```text
   Master                        Worker
     │                             │
     │  request #42                │
     ├────────────────────────────▶│
     │                             │ handler runs
     │                             │ side effects happen  ← the email is sent
     │                             │ response written
     │                    💀        │ worker dies here
     │◀─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ┘   (EOF, no response)
     │
     │  all the Master knows: "the worker died holding #42"
     ▼
   client gets  worker_crashed
```

From the Master's side these two are **indistinguishable**:

| What actually happened | What the Master sees |
|---|---|
| the worker died before running the handler | worker died holding #42 |
| the worker ran the handler, did the work, and died before the answer left | worker died holding #42 |

So `worker_crashed` means *"this request may or may not have taken
effect"*. Same for `request_timeout` and for a worker terminated on the
execution limit: the client stopped waiting, the work may still have
happened.

**What this means for you**

- Retrying a `worker_crashed` request is safe only if the action is
  idempotent. `calculate` - yes. `send_email` - you may send two.
  `charge_card` - do not.
- Make the handler idempotent (an idempotency key the action checks) if you
  intend to retry, or accept at-most-once and don't retry.
- The runtime deliberately does not offer an automatic retry, because it
  cannot make that decision correctly for you. PLAN.md Phase 15 records the
  same reasoning.

Exactly-once delivery is not achievable at this layer, by anyone. What
production systems provide is at-least-once plus application-level
idempotency, and that choice belongs to the application.

---

## What survives what

| Failure | Detected by | Effect | Recovery |
|---|---|---|---|
| **Worker crashes** | SIGCHLD, and independently by EOF on its socket | its in-flight request fails with `worker_crashed` | replacement forked immediately; pool back to size in well under a second |
| **Worker hangs** | execution timeout (60s) | SIGTERM, then SIGKILL on the next sweep | replacement forked; slot recovered rather than lost forever |
| **Worker leaks / ages** | recycling limits | drained after its current request | replacement launched *before* it leaves, so capacity never dips |
| **Client disconnects** | EOF on its socket | its pending requests are dropped at once, not left to time out | none needed; the worker's answer is discarded on arrival |
| **Client sends garbage** | framing/JSON decode fails | that client is dropped | other clients unaffected |
| **Client stops reading** | write buffer passes 4 MiB | connection declared broken and half-closed | Master's memory is bounded |
| **Queue overruns** | bounded queue | new requests rejected with `server_overloaded` | callers get an immediate, actionable answer |
| **fork() fails** | launch throws | logged; the pool keeps whatever workers it has | retried on the next sweep |
| **Master crashes** | nothing | **everything in memory is lost** | see below |

---

## The Master is a single point of failure

There is one Master. It holds, in memory only:

```text
   the request queue
   the pending-request registry (which client is waiting for what)
   worker state
   client connections
```

If it dies, all of that dies with it:

- every queued request is lost, with no notification to anyone;
- every in-flight request is lost - clients see their connection close;
- workers become orphans (their parent is gone; their sockets EOF, and
  `WorkerRunner` exits on that, so they do not linger - but nothing reaps
  or replaces them);
- the socket file is left behind. The next Master removes it on startup
  after probing that nobody is listening.

Nothing here is replicated, persisted, or failed over. There is no leader
election, no second Master, no write-ahead log. **A Master crash is total
data loss for work in progress.**

Running this for real would mean putting a process supervisor
(systemd, supervisord) in front of it for restarts, and accepting that a
restart drops in-flight work - or not using a single-node runtime.

---

## Scheduling: global FIFO, no fairness

One queue, first in first out, no per-client accounting:

```text
   client A ──┐
   client B ──┼──▶  [ one FIFO queue ]  ──▶  first free worker
   client C ──┘
```

A client that submits 10 000 requests is ahead of a client that submits one
right after, and will be served first - the classic noisy-neighbour
problem. There is no per-client quota, no round-robin between connections,
no priority.

This is a deliberate omission rather than an oversight: fair scheduling
needs a policy (per-connection? per-tenant? weighted?) that only an
application can choose. If you need it, the place to add it is
`RequestQueue` - it is the only component that would change.

---

## The IPC write contract

Two different write models, on purpose:

| | Model | Why |
|---|---|---|
| **Master → client** | buffered, non-blocking, flushed on writability events, capped at 4 MiB | a client is a stranger: it may be slow, stalled, or hostile, and the single-threaded Master must never wait on one |
| **Master → worker** | bounded blocking, up to `writeTimeoutSeconds` (5s) | a worker is ours: it exists only to read its socket, and it is blocked doing exactly that |

The worker path can therefore block the Master - **bounded at 5 seconds per
frame, in the worst case where a worker has stopped reading entirely**. In
that case the write is abandoned, the socket is marked broken and
half-closed, the worker sees EOF and exits, and SIGCHLD replaces it.

That bound is a real ceiling, not a theoretical one, and it is the one
place where a single misbehaving component can stall the whole loop. It is
accepted because a worker that has stopped reading its own socket is
already broken, and it is bounded so that "broken" cannot mean "forever".
Moving worker writes onto the same buffered path as clients is the obvious
next step if that bound ever proves too generous.

---

## Protocol versioning: none

The frame is `[4-byte length][JSON]`, and the JSON carries `type`, `id` and
`payload` - no version field. A v1 client and a v2 Master have no way to
negotiate, and an unknown `type` is a hard error that drops the connection.

Fine while the SDK and the Master ship together, which is the case here.
Anything else - an SDK distributed separately, a Master upgraded
independently of its callers - needs a version in the envelope before the
first incompatible change, not after.

---

## What is actually guaranteed

These hold, and are enforced by tests (`tests/E2E/InvariantsTest.php`):

1. A worker never runs two requests at once.
2. Every accepted request reaches exactly one terminal outcome - a
   response, or a named error. Never both, never neither.
3. A DEAD worker never receives work.
4. A DRAINING worker never receives new work; the one it holds finishes
   normally.
5. Worker replacement never pushes the pool past `maxWorkers`.
6. After shutdown: no worker outlives the Master, and the socket file is
   removed.

Invariant 2 is the one worth restating precisely, because the word
"outcome" is doing real work: it means the *caller is told something*. It
does not mean the request did or did not take effect - see the ambiguous
window at the top of this document.
