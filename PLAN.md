# PHP Worker Pool — Implementation Plan

## Goal

Build a persistent multi-process Worker Pool in PHP.

The system should allow PHP-FPM, CLI applications, cron jobs, or other PHP processes to send requests to a long-running Master process through a Unix Domain Socket.

The Master process must:

* asynchronously accept multiple client connections;
* receive and decode requests;
* put requests into a queue;
* delegate requests to available workers;
* receive responses from workers;
* route responses back to the correct client;
* monitor worker processes;
* handle overload and failures;
* support graceful shutdown.

Workers must be persistent processes.

A worker should not exit after processing a request.

---

# Final Architecture

```text
                           ┌─────────────┐
                           │   PHP-FPM   │
                           └──────┬──────┘
                                  │
                           ┌──────▼──────┐
                           │     CLI     │
                           └──────┬──────┘
                                  │
                           ┌──────▼──────┐
                           │    CRON     │
                           └──────┬──────┘
                                  │
                                  │
                        Unix Domain Socket
                                  │
                                  ▼
              ┌─────────────────────────────────┐
              │             MASTER              │
              │                                 │
              │          Async Event Loop       │
              │                                 │
              │  ┌───────────────────────────┐  │
              │  │ Client Connection Manager │  │
              │  └───────────────────────────┘  │
              │                                 │
              │  ┌───────────────────────────┐  │
              │  │      Request Queue        │  │
              │  └───────────────────────────┘  │
              │                                 │
              │  ┌───────────────────────────┐  │
              │  │      Dispatcher           │  │
              │  └───────────────────────────┘  │
              │                                 │
              │  ┌───────────────────────────┐  │
              │  │      Worker Registry      │  │
              │  └───────────────────────────┘  │
              │                                 │
              │  ┌───────────────────────────┐  │
              │  │ Pending Requests Registry │  │
              │  └───────────────────────────┘  │
              └───────────────┬─────────────────┘
                              │
                        IPC Channels
                              │
             ┌────────────────┼────────────────┐
             ▼                ▼                ▼
        ┌─────────┐      ┌─────────┐      ┌─────────┐
        │Worker #1│      │Worker #2│      │Worker #N│
        │         │      │         │      │         │
        │ Handler │      │ Handler │      │ Handler │
        └─────────┘      └─────────┘      └─────────┘
```

---

# Core Principles

## 1. Master does not perform heavy work

The Master is responsible only for:

```text
Accept connections
Read requests
Decode messages
Queue requests
Dispatch work
Receive worker responses
Route responses
Monitor workers
Handle lifecycle
```

The Master should not execute CPU-heavy tasks.

---

## 2. Workers are persistent

Workers are started once.

```text
Master
   │
   ├── fork Worker #1
   ├── fork Worker #2
   ├── fork Worker #3
   └── fork Worker #4
```

Each worker runs continuously:

```text
START

  │
  ▼

WAIT FOR REQUEST

  │
  ▼

PROCESS REQUEST

  │
  ▼

SEND RESPONSE

  │
  └──────────────► WAIT FOR REQUEST
```

---

## 3. Clients do not know about workers

The client communicates only with the Master.

```text
Client
   │
   ▼
Master
```

The client does not know:

```text
Which worker processed the request
How many workers exist
How IPC works
How requests are queued
```

---

## 4. Every request has a correlation ID

Example:

```json
{
    "type": "request",
    "id": "request-123",
    "payload": {
        "action": "calculate"
    }
}
```

The response contains the same ID:

```json
{
    "type": "response",
    "id": "request-123",
    "payload": {
        "result": 42
    }
}
```

This allows the Master to route responses to the correct client.

---

# Phase 0 — Project Setup

## Goal

Create the project structure.

```text
php-worker-pool/
│
├── bin/
│   ├── server.php
│   └── client.php
│
├── src/
│   ├── Master/
│   ├── Worker/
│   ├── Protocol/
│   ├── IPC/
│   ├── Queue/
│   ├── Client/
│   └── Support/
│
├── tests/
│
├── composer.json
├── README.md
└── PLAN.md
```

## Tasks

* [x] Create repository
* [x] Configure Composer
* [x] Configure PSR-4 autoloading
* [x] Add PHPStan
* [x] Add PHPUnit or Pest
* [x] Create basic README
* [x] Define minimum PHP version

---

# Phase 1 — Master ↔ Worker IPC Foundation

## Goal

Create one Worker process and communicate with it.

Architecture:

```text
             fork()

Master ───────────────── Worker

Master Socket ◄──────► Worker Socket
```

## Technologies

```text
pcntl_fork()
stream_socket_pair()
```

## Tasks

* [x] Create a socket pair
* [x] Fork a worker process
* [x] Close unnecessary socket descriptors
* [x] Send a message from Master to Worker
* [x] Read the message in Worker
* [x] Send a response from Worker to Master
* [x] Read the response in Master

## Example

```text
Master → PING
Worker → PONG
```

## Definition of Done

One Master and one Worker can exchange messages successfully.

---

# Phase 2 — Persistent Worker

## Goal

The Worker must process multiple requests without exiting.

Worker lifecycle:

```text
START
  │
  ▼
IDLE
  │
  ▼
RECEIVE REQUEST
  │
  ▼
BUSY
  │
  ▼
PROCESS
  │
  ▼
SEND RESPONSE
  │
  ▼
IDLE
```

## Tasks

* [x] Create Worker loop
* [x] Receive multiple requests
* [x] Process requests
* [x] Return responses
* [x] Support shutdown command

Pseudo-code:

```php
while ($running) {
    $request = receiveRequest();

    $response = handle($request);

    sendResponse($response);
}
```

## Definition of Done

The same Worker can process at least 100 consecutive requests.

---

# Phase 3 — Message Protocol

## Problem

Sockets are streams.

This does not guarantee that one `fread()` call equals one message.

For example:

```text
{"id":"abc","pay
```

and later:

```text
load":"hello"}
```

Or multiple messages can arrive together:

```text
MESSAGE_1MESSAGE_2MESSAGE_3
```

Therefore, the system needs message framing.

---

## Solution

Use a length-prefixed protocol.

```text
┌──────────────┬────────────────────┐
│ Payload Size │ Payload            │
│ 4 bytes      │ N bytes            │
└──────────────┴────────────────────┘
```

Example:

```text
[00000042][{"type":"request","id":"123"}]
```

---

## Tasks

* [x] Create Message class
* [x] Create Encoder
* [x] Create Decoder
* [x] Implement length-prefixed messages
* [x] Support partial reads
* [x] Support multiple messages in one read
* [x] Add malformed message handling

## Message Types

```text
request
response
error
shutdown
ping
pong
```

## Definition of Done

The protocol correctly handles:

* [x] partial messages
* [x] multiple messages
* [x] large messages
* [x] malformed messages

---

# Phase 4 — Worker Process Abstraction

## Goal

Create an abstraction representing a Worker.

Example:

```text
WorkerProcess
│
├── PID
├── IPC Socket
├── State
└── Current Request
```

## Worker States

```text
STARTING
IDLE
BUSY
STOPPING
DEAD
```

## Tasks

* [x] Create WorkerProcess class
* [x] Store PID
* [x] Store IPC socket
* [x] Store worker state
* [x] Store current request ID
* [x] Implement state transitions

## State Machine

```text
STARTING
    │
    ▼
   IDLE
    │
    ▼
   BUSY
    │
    ▼
   IDLE
    │
    ▼
STOPPING
    │
    ▼
   DEAD
```

## Definition of Done

The Master can determine which workers are idle and which are busy.

---

# Phase 5 — Worker Pool

## Goal

Run multiple Worker processes.

Architecture:

```text
             MASTER
                │
       ┌────────┼────────┐
       ▼        ▼        ▼
    Worker1  Worker2  Worker3
```

## Tasks

* [x] Configure worker count
* [x] Start N workers
* [x] Create IPC channel for each worker
* [x] Register workers
* [x] Track worker state
* [x] Find an available worker

## Initial Configuration

```php
$workerCount = 4;
```

## Definition of Done

Four Workers can process four requests simultaneously.

**Post-review fix:** `WorkerPool`'s constructor launched its configured
worker count in a plain loop, so a `launch()` failure partway through
(`ForkedWorkerLauncher` throws on a failed `pcntl_fork()`, since Phase 20's
review) propagated straight out of `new WorkerPool(...)` with no
`WorkerPool` instance for anyone to call `stop()` on - any workers already
forked in that same construction attempt were orphaned, both the process
and its socket pair. The constructor now wraps the loop in try/catch: on
failure it calls `$this->stop(0.0)` on whatever was already launched (kills
and reaps them) before rethrowing. Covered by `WorkerPoolTest::
testConstructorStopsAlreadyLaunchedWorkersIfALaterLaunchFails`.

**Post-review fix (second pass):** the same unguarded-`launch()` problem
existed at three more call sites the first pass's fix didn't reach -
`advanceReload()` (Phase 19), `reapDeadWorkers()`'s crash-replacement
(Phase 15), and `scaleUp()` (Phase 20). Worse than the constructor case in
two of the three: `reapDeadWorkers()` runs from a SIGCHLD handler, and an
uncaught exception there aborted reaping/replacing the rest of that
batch's dead workers, not just the one that failed; `scaleUp()` runs from
`Autoscaler::check()` in Master's main loop, where an uncaught exception
would have taken the whole Master down. All three now catch and recover
instead of propagating: `advanceReload()` requeues the pid for the next
opportunity to retry (`reapDeadWorkers()` re-triggers it once headroom
frees up), `reapDeadWorkers()` keeps processing the rest of its batch, and
`scaleUp()` (now returns `int`, not `void`) stops early and reports how
many it actually launched. Covered by `WorkerPoolTest::
testReloadSurvivesALaunchFailureAndKeepsTheWorkerPendingForRetry`,
`testReloadCompletesOnceARetriedLaunchSucceeds`,
`testReapDeadWorkersProcessesTheWholeBatchEvenWhenOneReplacementLaunchFails`,
and `testScaleUpStopsEarlyWithoutThrowingWhenALaunchFails`.

---

# Phase 6 — Request Queue

## Problem

There can be more requests than workers.

```text
Workers: 4
Requests: 100
```

Architecture:

```text
Requests
   │
   ▼
┌───────────────┐
│ Request Queue │
└───────┬───────┘
        │
        ▼
   Idle Workers
```

## Initial Implementation

Use:

```text
SplQueue
```

## Tasks

* [x] Create RequestQueue abstraction
* [x] Add enqueue()
* [x] Add dequeue()
* [x] Add size()
* [x] Add empty check

## Definition of Done

Requests wait in the queue when all workers are busy.

---

# Phase 7 — Dispatcher

## Goal

Connect the Request Queue with the Worker Pool.

Logic:

```text
Request arrives
       │
       ▼
Request Queue
       │
       ▼
Is Worker IDLE?
       │
      YES
       │
       ▼
Dispatch Request
       │
       ▼
Worker becomes BUSY
```

When the Worker finishes:

```text
Worker Response
       │
       ▼
Worker becomes IDLE
       │
       ▼
Dispatch next request
```

## Tasks

* [x] Create Dispatcher
* [x] Find idle worker
* [x] Take request from queue
* [x] Send request to worker
* [x] Mark worker as BUSY
* [x] Dispatch next request when worker becomes IDLE

## Important

Do not use polling.

Do not do:

```text
while true:
    check queue
```

Dispatch should happen because of events:

```text
New Request
        ↓
dispatch()

Worker Response
        ↓
dispatch()
```

## Definition of Done

The Worker Pool automatically processes queued requests.

---

# Phase 8 — Async Master Event Loop

## Goal

The Master must asynchronously handle multiple event sources.

The Master listens to:

```text
1. Server Socket
2. Client Sockets
3. Worker IPC Sockets
```

Use:

```text
stream_select()
```

Architecture:

```text
                   EVENT LOOP
                       │
        ┌──────────────┼──────────────┐
        ▼              ▼              ▼
 Server Socket      Clients         Workers
```

## Event Types

### Server Socket

```text
Readable
    │
    ▼
Accept Client
```

### Client Socket

```text
Readable
    │
    ▼
Read Data
    │
    ▼
Decode Request
    │
    ▼
Queue Request
```

### Worker Socket

```text
Readable
    │
    ▼
Read Response
    │
    ▼
Route Response
```

## Tasks

* [x] Create EventLoop
* [x] Register readable sockets
* [x] Run stream_select()
* [x] Detect event source
* [ ] Handle client events — no client sockets yet, see Phase 9/10
* [x] Handle worker events

## Definition of Done

The Master can simultaneously handle multiple clients and workers.

Partial: `Dispatcher` now drives its worker I/O entirely through `EventLoop`
(`src/EventLoop/EventLoop.php`) instead of a hand-rolled `stream_select()` —
worker sockets are registered while busy and deregistered once answered or
dead. Client sockets aren't handled yet because they don't exist until
Phase 9/10 build the Unix socket server; those phases should register their
sockets with the same `EventLoop` rather than adding a second loop.

---

# Phase 9 — Unix Domain Socket Server

## Goal

Allow external PHP processes to communicate with the Worker Pool.

Socket:

```text
/run/php-worker-pool.sock
```

or during development:

```text
/tmp/php-worker-pool.sock
```

Architecture:

```text
PHP-FPM
   │
CLI
   │
CRON
   │
   ▼
Unix Socket
   │
   ▼
MASTER
```

## Tasks

* [x] Create Unix Domain Socket
* [x] Start socket server
* [x] Accept connections
* [x] Set sockets to non-blocking mode
* [x] Remove socket file during shutdown
* [x] Handle stale socket file on startup

## Definition of Done

A separate PHP process can connect to the Master.

---

# Phase 10 — Client Connection Management

## Goal

Manage multiple client connections.

The Master must store:

```text
Client ID
Socket
Read Buffer
Write Buffer
Connection State
```

## Tasks

* [x] Create ClientConnection class
* [x] Store socket
* [x] Store read buffer — provided by Socket's own MessageDecoder buffering
      (see IPC/Socket.php), no separate buffer needed on ClientConnection
* [x] Store write buffer — Socket::write() used to do a single blind
      fwrite() with no queuing/retry, exactly the gap this line originally
      flagged. Once Phase 11 started actually writing responses to clients
      it became a real problem: client sockets are non-blocking, and a
      full kernel send buffer makes fwrite() return fewer bytes than asked
      instead of blocking - the rest of the frame was silently dropped
      instead of retried (found by a later code review, not caught by any
      test until one was written for it - see SocketTest). write() now
      loops until the whole frame is out, waiting on the socket's
      writability via stream_select() between attempts, bounded by a
      writeTimeoutSeconds so one stuck/slow client can't stall the
      single-threaded Master's other clients forever. A genuinely dead
      peer is handled exactly as before (fwrite() returning false - give
      up silently, the next read() reports it).
* [x] Handle disconnect
* [x] Remove dead clients — also covers a client sending unparseable bytes
      (MalformedMessageException), not just a clean disconnect

## Important

A disconnected client must not crash the Master.

Verified live: a client that disconnects abruptly mid-request, and a client
that sends malformed framing, both get dropped without affecting the Master
or other clients/workers (see ClientRegistryTest and a manual end-to-end run).

**Post-review fix:** "Remove dead clients" removed the client from
ClientRegistry's own tracking, but nothing told PendingRequestRegistry
(Phase 11) that client was gone - a request still in flight for it just sat
until its 30s timeout for no reason, since there was no longer anyone to
deliver the response to. ClientRegistry now takes an optional
`onDisconnect` callback, invoked from the same `remove()` that already
handles both the clean-disconnect and malformed-frame cases; Master wires
it to `PendingRequestRegistry::removeByClient()` (new). See Phase 11's own
note.

## Definition of Done

Multiple clients can connect and disconnect safely.

---

# Phase 11 — Correlation IDs and Pending Requests

## Problem

Workers can complete requests in any order.

Example:

```text
Client A → Request #1
Client B → Request #2
Client C → Request #3
```

Workers:

```text
Worker 1 → Request #3
Worker 2 → Request #1
Worker 3 → Request #2
```

Responses:

```text
#1
#3
#2
```

The Master must know where every response belongs.

---

## Solution

Maintain a Pending Requests Registry.

```text
Request ID
     │
     ▼
Client Connection
```

Example:

```php
$pendingRequests = [
    'request-1' => ClientConnection,
    'request-2' => ClientConnection,
];
```

## Pending Request Data

```text
Request ID
Client
Created Time
Worker
Deadline
```

## Tasks

* [x] Generate unique request ID — Master-assigned (PendingRequestRegistry),
      not the client's own id: two clients (or one, by mistake) reusing the
      same id can't collide, since the registry's key is never the id a
      client chose. The client's original id is restored before its
      response is written back.
* [x] Register pending request
* [x] Assign worker — already handled by the existing Dispatcher/WorkerPool
      from Phase 6/7; this phase just feeds client requests into it
* [x] Find request by ID
* [x] Route response to client
* [x] Remove completed request — resolve() is one-shot (unset on lookup)

## Definition of Done

Responses always return to the correct client.

Verified live: two clients connected simultaneously, both sending a request
under the identical correlation id "same-id" with different payloads - each
received back exactly its own response, never the other's (see
PendingRequestRegistryTest for the unit-level collision case, and a manual
end-to-end run for the live one).

**Post-review fix:** "Remove completed request" only ever covered the
happy path (a response actually arrives) - a client disconnecting with a
request still in flight left that entry tracked with nothing left to route
a response to, until Phase 14's 30s timeout eventually swept it. New
`PendingRequestRegistry::removeByClient()`, called from Phase 10's new
`ClientRegistry` `onDisconnect` hook, removes it immediately instead.

---

# Phase 12 — PHP Client

## Goal

Create a reusable PHP client.

Example API:

```php
$client = new WorkerPoolClient(
    '/tmp/php-worker-pool.sock'
);

$response = $client->call(
    'calculate',
    [
        'a' => 10,
        'b' => 20,
    ]
);
```

## Tasks

* [x] Connect to Unix Socket
* [x] Encode request — reuses IPC/Socket + Protocol/MessageEncoder, same as
      the Master side
* [x] Send request
* [x] Wait for response — bounded by $timeoutSeconds (default 5s), not an
      unbounded block; composes Socket::readAvailable() in a loop rather
      than Socket::read()'s indefinite wait (fine for a worker, wrong for a
      client that must not hang a PHP-FPM request forever)
* [x] Decode response
* [x] Handle connection errors — ConnectionFailedException
* [x] Handle timeouts — RequestTimedOutException

Note: WorkerRunner::handle() now routes on `action` via `match` - `calculate`
does a real `a + b` (see Worker/WorkerRunner.php); anything else (or no
action at all) still falls back to echoing the payload back, which is what
the rest of the test suite's plain-payload requests rely on.

Socket::readAvailable() changed from an int-microseconds timeout to a float-
seconds one while implementing this - the old signature couldn't safely
represent multi-second waits, and this client needs up to $timeoutSeconds
(seconds, not micro-). Every other caller already used the 0/default
non-blocking poll, so this is not a behavior change for them.

## Supported Environments

```text
PHP-FPM
CLI
Cron
Queue Consumers
Other PHP Processes
```

## Definition of Done

The same client library works in both PHP-FPM and CLI.

Verified live: bin/client.php against a real running bin/server.php gets a
real round-trip response; pointing WorkerPoolClient at a socket nothing is
listening on throws ConnectionFailedException; a server that reads the
request but never replies throws RequestTimedOutException after the
configured timeout (see WorkerPoolClientTest).

---

# Phase 13 — Backpressure

## Problem

The system can receive more requests than it can process.

```text
Workers: 10

Incoming Requests: 100,000

Queue: ∞
```

Without limits:

```text
Queue grows
     │
     ▼
Memory grows
     │
     ▼
OOM
     │
     ▼
Process crashes
```

---

## Solution

Limit queue size.

Example:

```text
maxQueueSize = 10,000
```

When the queue is full:

```text
Request
   │
   ▼
QUEUE FULL
   │
   ▼
Reject Request
```

## Response

```json
{
    "type": "error",
    "error": "server_overloaded"
}
```

## Tasks

* [x] Configure maximum queue size — RequestQueue(?int $maxSize), Master
      sets it to 10,000 (this phase's own example value)
* [x] Reject requests when full — Dispatcher::dispatch() now returns bool;
      Master writes the client an ERROR message on false instead of
      queueing forever
* [x] Track rejected requests — RequestQueue::rejectedCount()
* [x] Add queue metrics — RequestQueue::size()/rejectedCount() are readable
      now; nothing surfaces them anywhere yet (no endpoint, no logging) -
      that's Phase 17 (Metrics), not this one

## Definition of Done

The Master survives overload without uncontrolled memory growth.

Verified: unit tests fill a size-limited queue and confirm the next
dispatch() is rejected and counted (see DispatcherTest,
RequestQueueTest); the ERROR frame's wire format was checked byte-for-byte
against this phase's own JSON example
(`{"type":"error","id":"req-1","payload":{"error":"server_overloaded"}}`)
and round-trips through the same encoder/decoder as every other message.

**Post-review fix:** `Dispatcher::run()` (the synchronous batch API, not
used by Master's `dispatch()` event-driven path but still public surface)
had gained an `isFull()` check ahead of its enqueue loop, but checked it
only once before the loop rather than against the batch size - a batch
bigger than the remaining capacity still got enqueued in full, silently
growing the queue past `maxSize`. `RequestQueue` now has
`hasCapacityFor(int $additional)`; `run()` checks the whole batch up front
and rejects atomically (throws before queueing anything) rather than
partway through. Covered by `DispatcherTest::
testRunRejectsABatchLargerThanQueueCapacity`.

---

# Phase 14 — Request Timeouts

## Problem

A request may never complete.

```text
Request
   │
   ▼
Worker
   │
   ▼
Hangs forever
```

## Solution

Every request has a deadline.

```text
Created: 10:00:00

Timeout: 5 seconds

Deadline: 10:00:05
```

## Tasks

* [x] Add request deadline — PendingRequest::$deadline, set at register()
      time from a configurable per-request timeout (Master:
      REQUEST_TIMEOUT_SECONDS = 30s)
* [x] Detect expired requests — PendingRequestRegistry::removeExpired(),
      swept once per second by Master's main loop (see EventLoop::tick()'s
      new optional timeout - it now returns on a schedule even with zero
      socket activity, not just on real events or a signal)
* [x] Return timeout response — {"type":"error","payload":{"error":
      "request_timeout"}}, same ERROR convention as Phase 13's overload
      response, sent under the client's own original id
* [x] Remove pending request — removeExpired() is one-shot, same as resolve()
* [x] Track timeout metrics — PendingRequestRegistry::timeoutCount()

## Future Improvement

Implement:

```text
Timer Heap
```

instead of scanning all requests.

## Definition of Done

Clients receive a timeout response when work takes too long.

Verified live: a client whose worker never answers gets
`{"type":"error","id":"my-id","payload":{"error":"request_timeout"}}` back
under its own original id after the configured timeout, over a real Unix
socket connection; the normal (non-timing-out) path was re-checked against
a real running server afterward to confirm nothing regressed.

---

# Phase 15 — Worker Crash Detection

## Problem

Workers can crash.

```text
Worker #3

Processing Request
       │
       ▼
      💀
```

The Master must recover.

## Detection

Use:

```text
SIGCHLD
```

and/or:

```text
Closed IPC Socket
```

## Recovery

```text
Worker dies
     │
     ▼
Detect failure
     │
     ▼
Remove worker
     │
     ▼
Handle active request
     │
     ▼
Fork new worker
```

## Active Request Strategy

Initially:

```text
Return Error
```

Later:

```text
Retry if operation is retryable
```

## Tasks

* [x] Handle SIGCHLD — Master registers a handler alongside SIGINT/SIGTERM
* [x] Detect dead workers — WorkerPool::reapDeadWorkers() (WNOHANG, so it
      never blocks and is safe to call from the signal handler); this also
      catches an IDLE worker crashing, which Dispatcher's read-based
      detection can't see at all (it only watches busy workers)
* [x] Remove worker
* [x] Fail active request — two independent, redundant paths, whichever
      notices first "wins" (PendingRequestRegistry::resolve() is one-shot,
      so a second attempt is a harmless no-op): SIGCHLD via
      WorkerPool::reapDeadWorkers() catches it however busy/idle the worker
      was; Dispatcher::watch() catching ConnectionClosedException catches
      it specifically for a busy worker, usually just as fast, via the
      worker's own socket going to EOF. Client gets
      {"type":"error","payload":{"error":"worker_crashed"}}
* [x] Start replacement worker — reapDeadWorkers() launches one immediately,
      unless the pool is mid-stop() (would just orphan it)

Active Request Strategy: "Return Error" (this phase's "Initially") is what's
implemented. "Retry if operation is retryable" (this phase's "Later") is not
- retrying changes request semantics (only safe for idempotent operations,
which nothing in this codebase currently distinguishes) and isn't asked for
by this phase's own Definition of Done.

## Definition of Done

The Worker Pool automatically recovers after a Worker crash.

Verified live: killing a real worker process mid-run (docker `kill -9`) —
the pool replaced it within about a second and kept serving requests
normally afterward; unit tests cover both an idle worker crashing
(WorkerPoolTest) and a busy one crashing mid-request, confirming the
synthesized worker_crashed response carries the right request id
(DispatcherTest).

---

# Phase 16 — Graceful Shutdown

## Goal

Stop the system without losing running requests.

Signal:

```text
SIGTERM
```

## Shutdown Process

```text
SIGTERM
   │
   ▼
Stop accepting new clients
   │
   ▼
Stop accepting new requests
   │
   ▼
Finish queued/running requests
   │
   ▼
Send shutdown command to workers
   │
   ▼
Wait for workers
   │
   ▼
Remove Unix Socket
   │
   ▼
Exit
```

## Safety Timeout

```text
gracefulShutdownTimeout = 30 seconds
```

After timeout:

```text
SIGTERM
   │
No exit
   │
   ▼
SIGKILL
```

## Tasks

* [x] Handle SIGTERM — already done since Phase 9; this phase is about what
      happens next, not catching the signal itself
* [x] Stop accepting new connections — Master::shutdown() closes the
      UnixSocketServer (removing its listener from the shared EventLoop)
      before doing anything else
* [x] Drain requests — Master::shutdown() keeps ticking the same $loop,
      bounded by GRACEFUL_SHUTDOWN_TIMEOUT_SECONDS (30s, this phase's own
      example value), until PendingRequestRegistry empties on its own.
      Anything still pending once that budget runs out gets
      {"type":"error","payload":{"error":"server_shutting_down"}} instead
      of being silently dropped
* [x] Shutdown workers — WorkerPool::stop() (unchanged trigger, extended
      behavior - see below)
* [x] Wait for children — WorkerPool::stop() waits for exits, bounded by
      whatever's left of the same overall shutdown budget, then SIGKILLs
      anything still alive rather than blocking forever
* [x] Remove socket file — UnixSocketServer::close(), unchanged from Phase 9

## Definition of Done

The system shuts down without immediately killing active work.

Verified live: a request still in flight when shutdown begins and whose
worker answers within the safety timeout gets its real response, not a
shutdown error - the client only ever sees `{"type":"error","payload":
{"error":"server_shutting_down"}}` when the worker doesn't answer in time.
Normal shutdown (nothing pending) stays fast and clean, same as before this
phase. WorkerPoolTest proves stop()'s SIGKILL fallback actually fires
against a worker that never reads the SHUTDOWN message, rather than hanging.

---

# Phase 17 — Metrics

## Goal

Make the Worker Pool observable.

## Worker Metrics

```text
workers_total
workers_idle
workers_busy
workers_dead
```

## Request Metrics

```text
requests_total
requests_completed
requests_failed
requests_timeout
requests_rejected
```

## Queue Metrics

```text
queue_size
queue_wait_time
```

## Performance Metrics

```text
request_duration
worker_processing_time
```

## Example Output

```text
Worker Pool Status

Workers:
  Total: 8
  Idle: 3
  Busy: 5

Queue:
  Pending: 124

Requests:
  Total: 100000
  Completed: 99800
  Failed: 150
  Timeout: 50
```

## Status

Implemented: WorkerPool::countIdle()/countBusy()/totalCrashed() (lifetime,
not a live count - reapDeadWorkers() removes and replaces a crashed worker
essentially immediately, so a live count would almost always read 0),
RequestQueue::size()/rejectedCount() (already existed),
PendingRequestRegistry::timeoutCount() (already existed), and a new
RequestMetrics (requests_total/completed/failed) that Master updates at
each point a request's outcome is decided. MetricsCollector::snapshot()
pulls all of it into one Metrics value object; Metrics::format() matches
this phase's own Example Output above. Exposed via `kill -USR1 <pid>`,
dumping the snapshot to stdout - the usual Unix convention for "report your
stats now" (nginx and php-fpm both do the same), and it needed no new wire
protocol or endpoint.

Not implemented: the "Performance Metrics" (request_duration,
worker_processing_time) and queue_wait_time. All three need timestamps
nothing in this codebase tracks yet (when a request was queued, when a
worker actually picked it up), and this phase - unlike every other one -
has no Tasks/Definition of Done section forcing the question, so this was
a judgment call: add the counters that were cheap and directly derivable
from state that already exists, not invent the extra bookkeeping speculatively.

Verified live: 3 real requests through a running server, then SIGUSR1 -
the dumped snapshot read Workers Total 4/Idle 4/Busy 0 and Requests Total
3/Completed 3, matching reality exactly.

---

# Phase 18 — Multiple Requests Per Connection

## Goal

Support request multiplexing.

One connection:

```text
Client
   │
   ├── Request #1
   ├── Request #2
   ├── Request #3
   │
   ▼
Master
```

Responses may arrive:

```text
Response #2
Response #1
Response #3
```

## Future Client API

```php
$request1 = $client->send('task1');
$request2 = $client->send('task2');
$request3 = $client->send('task3');

$response = $request2->await();
```

## Definition of Done

One client connection can have multiple pending requests simultaneously.

## Status

Already true, as a consequence of earlier phases' design rather than new
work this phase: ClientRegistry's read handler already decodes and forwards
every message readAvailable() returns in one pass (not just the first), and
PendingRequestRegistry's keys are per-request dispatch ids, never tied to
which connection sent them - nothing stops multiple entries from pointing
at the same ClientConnection at once, and each is routed back independently
by id regardless of arrival order. Verified rather than built: two tests
proving it (ClientRegistryTest - three requests on one connection all reach
onRequest; a second answering them out of order and confirming each
response still lands on its own original id) and a live run against a real
server (one real connection, three requests, real workers finishing them
out of order, each response still correctly matched back).

The "Future Client API" (`$client->send()->await()`) was explicitly this
phase's forward-looking sketch rather than part of its Definition of Done,
and WorkerPoolClient stayed synchronous at the time. It exists now - see
"Client-side multiplexing" below.

---

# Phase 19 — Graceful Reload

## Goal

Reload Workers without stopping the Master.

Signal:

```text
SIGHUP
```

Architecture:

```text
Old Workers
     │
     ▼
Start New Workers
     │
     ▼
New Requests → New Workers
     │
     ▼
Old Workers Finish Work
     │
     ▼
Old Workers Shutdown
```

## Definition of Done

Workers can be replaced without dropping client connections.

## Status

WorkerPool::reload() starts a full new generation immediately (available
for new dispatch right away) and marks the outgoing generation retiring -
excluded from new dispatch, but a busy one is left completely alone until
it finishes: Dispatcher never even knows a reload happened, so its response
is routed back to the client exactly as if nothing had changed.
retireIdleWorkers(), polled once per Master tick, is what actually shuts a
retiring worker down once it's confirmed available (idle, or never
dispatched to at all). Wired to SIGHUP, the traditional Unix "reload"
signal (nginx, php-fpm again).

Two bugs turned up only under a real live run, not the unit tests written
alongside the initial implementation - both fixed:
- retireIdleWorkers() checked for state exactly IDLE, so a retiring worker
  that was never dispatched to at all (still STARTING) would never retire.
- reapDeadWorkers() (the Phase 15 SIGCHLD handler) didn't know a STOPPING
  worker's exit could be an intentional retirement rather than a crash, so
  it launched a second, unwanted replacement for every one that finished
  retiring - on top of the one reload() already started.
Also fixed along the way: WorkerPool::stop() would try to write to and
close a retiring worker's already-closed socket if called before that
worker had actually been reaped (a live-only ordering the FakeWorkerLauncher
unit tests happened not to exercise either).

Verified live: against a real running server, four original worker pids,
SIGHUP, and within under a second exactly four new pids (none from the
original set) - not five, not eight left stranded. A separate script proved
the more important half directly: a request already in flight to a worker
at the moment of reload() still gets its real response delivered to the
client once that worker finishes, not a dropped connection.

---

# Phase 20 — Autoscaling Workers

## Goal

Dynamically change the number of Workers.

Example:

```text
Queue is growing
      │
      ▼
Start more workers
```

When idle:

```text
Low load
    │
    ▼
Stop extra workers
```

Configuration:

```text
minWorkers = 2
maxWorkers = 16
```

Possible scaling signals:

```text
Queue Size
Worker Utilization
Request Latency
```

## Status

Autoscaler::check() (polled once per Master tick, same as the other per-tick
sweeps) scales up by `step` when the queue has work and no worker is idle
to take it, scales down by `step` when idle workers sit above `minWorkers`
and the queue is empty - bounded by [minWorkers, maxWorkers] either way,
with a cooldown between actions so one burst can't cause an immediate
scale-up followed by a scale-down before the change had any chance to
matter. Master now starts the pool at minWorkers (2) instead of a fixed 4,
growing it under load rather than starting pre-scaled - minWorkers/
maxWorkers use this phase's own example values (2/16).

Uses only "Queue Size" and a derived "Worker Utilization" (busy vs. idle
counts, from Phase 17) of the three signals the phase lists as merely
*possible* - "Request Latency" would need per-request timing this codebase
deliberately doesn't track anywhere (see Phase 17's own scope notes on
request_duration).

scaleUp()/scaleDown() live on WorkerPool itself, reusing Phase 19's
retiring mechanism for scale-down (mark a subset idle-and-safe-to-retire,
then let retireIdleWorkers() shut them down) rather than inventing a
second one - a scaled-down worker is retired exactly the same way a
reload()'s outgoing generation is, just for a handful of workers instead
of all of them.

Verified live: pool starts at 2; a burst of 60 concurrent requests grows it
to 4 within a second (sampled repeatedly during the burst, not just before
and after); after ~15s idle it settles back to exactly 2. All 60 requests
completed successfully throughout (confirmed via the Phase 17 SIGUSR1
snapshot: Total 60, Completed 60, Failed 0).

**Post-review fixes** (found by a full-project code review after Phase 20
landed, both interactions between this phase and Phase 19 that no earlier
phase's tests exercised together):

- `Autoscaler::check()` computed idle capacity as `count() - countBusy()`,
  which counted a retiring (STOPPING) worker as spare idle capacity even
  though it can never actually be dispatched to - it could silently stall
  scale-up under real backlog. Fixed to use `WorkerPool::countIdle()`
  (already correct via `isAvailable()`) instead of reimplementing it.
- `WorkerPool::reload()` launched a full duplicate generation unconditionally,
  sized to match the *current* pool - harmless when the pool was always a
  fixed size, but once Autoscaler can grow it up to `maxWorkers`, a reload
  arriving near that ceiling could transiently double the live process count
  past it. `WorkerPool` now takes an optional `maxWorkers` (Master passes its
  own), and `reload()` replaces the outgoing generation in waves via
  `advanceReload()` - launching only as many replacements as fit under the
  cap, then launching more as retiring workers actually get reaped and free
  up headroom (`reapDeadWorkers()` resumes it). Guarantees forward progress
  (at most a 1-worker transient overshoot) if reload() is requested while
  already at the cap, rather than deadlocking.

Covered by `AutoscalerTest` (unchanged assertions still pass, confirming no
behavior regression) and a new `WorkerPoolTest::
testReloadDoesNotExceedMaxWorkersWhenPoolIsNearCapacity` regression test.

**`Support/Clock`** (from the original "Suggested Project Structure"
sketch, added now rather than up front): `Autoscaler::check()`'s cooldown
and `PendingRequestRegistry::register()`'s deadline both called
`microtime(true)` directly, which made their time-dependent behavior
untestable except via real sleeps or workarounds (`AutoscalerTest` used
`cooldownSeconds: 0.0` to sidestep the cooldown entirely;
`PendingRequestRegistryTest` used a negative `$timeoutSeconds` to fake an
already-past deadline - both still work and are unchanged). Both now take
an optional `Clock` (default `SystemClock`, i.e. `microtime(true)` -
behavior identical unless a test overrides it). `FakeClock` (under
`tests/Support/`, same test-double convention as `FakeWorkerLauncher`) lets
a test move time forward explicitly - new coverage: `AutoscalerTest::
testCooldownAllowsScalingAgainOnceItElapses` (previously impossible to
assert without a real 5s sleep) and `PendingRequestRegistryTest`'s
deadline tests, rewritten to advance a `FakeClock` past the deadline
instead of relying on a negative timeout trick.

`Support/IdGenerator` (the sketch's other suggestion) was deliberately not
added - see the Actual Structure note above.

---

# Post-Phase-20 Hardening (Third Review Pass)

A full-project review after everything above landed found no bugs in any
single phase's happy path - what it found was in the seams: signal handling
across fork(), framing after a failed write, and the two places where one
misbehaving peer could still take down or stall the whole single-threaded
Master. All fixed:

- **A worker's malformed bytes crashed the Master.** `Dispatcher::watch()`
  caught only `ConnectionClosedException`; `MalformedMessageException` from
  a desynced worker stream propagated through `EventLoop::tick()` straight
  out of Master's main loop. Both are now treated as a worker crash (the
  in-flight request fails with `worker_crashed`, the socket is closed so a
  still-running desynced worker exits and gets replaced via SIGCHLD).
  Covered by `DispatcherTest::testMalformedBytesFromAWorkerAreTreatedAsACrashNotAMasterCrash`.

- **A given-up write desynced the stream instead of ending it.**
  `Socket::write()` hitting `writeTimeoutSeconds` mid-frame returned
  silently, leaving the connection open with half a frame sent - the next
  write would be parsed by the peer as the rest of that frame. The socket
  now marks itself broken (all later writes dropped) and shuts down its
  sending side, so the peer sees clean EOF instead of garbage. Covered by
  `SocketTest::testAGivenUpWriteBreaksTheSocketInsteadOfDesyncingTheStream`.

- **One slow client could stall the Master for up to 5s per frame.** Client
  writes went through `Socket::write()`'s bounded-blocking retry - fine for
  workers and the SDK, wrong inside the single-threaded Master.
  `ClientConnection` now buffers writes and flushes them on EventLoop
  writability events (`EventLoop` gained `addWritable`/`removeWritable`),
  never blocking; a stuck client's buffer is capped (4 MiB) rather than
  growing without bound, and `Master::shutdown()` drains remaining client
  buffers within the same shutdown budget before exiting. Covered by
  `ClientConnectionTest` and `EventLoopTest::testTickInvokesWritableHandlerUntilRemoved`.

- **Forked workers inherited Master's signal handlers and mask.** A worker
  forked after Master registered its handlers (a crash replacement, a
  scale-up) carried closures over Master state: SIGHUP delivered to it would
  run `reload()` *inside the worker* and fork grandchildren. The child now
  resets every handler Master registers to `SIG_DFL` and clears the
  inherited signal mask before entering the worker loop
  (`ForkedWorkerLauncher`).

- **Async SIGCHLD could interleave with WorkerPool's own mutations.** With
  `pcntl_async_signals(true)`, `reapDeadWorkers()` could fire between any
  two statements of `stop()`/`reload()`/`scaleUp()`/`scaleDown()`/
  `retireIdleWorkers()`, and `stop()`'s `waitpid()` loop competed with the
  handler's for the same child exits. Each mutating operation now runs with
  SIGCHLD delivery deferred (`pcntl_sigprocmask`; blocked, not ignored -
  delivered the moment the operation ends), making it atomic with respect
  to the reaper. Three narrower windows of the same class, also closed:
  Master's SIGCHLD handler no longer writes to clients from signal context
  (it could reenter an unfinished buffered write on the same connection) -
  it only reaps and records, and the main loop delivers the worker_crashed
  errors; `WorkerPool::write()` re-checks the worker atomically and returns
  null if it was reaped between `getAvailable()` and the dispatch
  (`Dispatcher::pump()` requeues and picks another); and
  `WorkerProcess::finishRequest()` tolerates a worker the reaper marked
  DEAD a moment earlier instead of throwing from the timing window.

- **Autoscaler scaled the fresh generation away right after a reload.**
  Found by this pass's live SIGHUP run (unit tests all green throughout):
  right after reload() on an idle pool, `count()` briefly sees both
  generations (new available + old STOPPING), so `Autoscaler::check()`
  concluded "4 workers, floor is 2, queue empty" and called `scaleDown(2)` -
  and since scaleDown() skips already-retiring workers, the only candidates
  were the NEW generation. Deterministic: an idle pool's reload always ended
  at zero workers. Scaling decisions now use the new
  `WorkerPool::countActive()` (workers that are staying - not retiring, not
  STOPPING, not DEAD) for the floor, keeping `count()` only for the
  maxWorkers ceiling, which caps live processes on purpose. Covered by
  `AutoscalerTest::testDoesNotScaleDownTheFreshGenerationDuringAReload` and
  re-verified live (SIGHUP swaps the full generation, requests keep
  answering, pool stays at size through the cooldown).

- **A handler exception killed the whole worker.** `WorkerRunner` now
  answers the request with `{"error":"handler_failed"}` and keeps serving -
  a handler bug cost a reap-and-refork and a `worker_crashed` for something
  an error reply answers just as well. Covered by `PersistentWorkerTest::
  testHandlerFailureAnswersWithAnErrorAndTheWorkerSurvives`.

- **Recovered failures vanished without a trace.** The launch failures
  WorkerPool deliberately survives (crash replacement, reload wave,
  scale-up) were empty `catch` blocks - no exception, no metric, no record.
  A minimal `Support/Logger` (one method; `StderrLogger` in Master,
  `NullLogger` default) now records them. Deliberately not PSR-3: this
  codebase has no dependencies, and these sites need "record that this
  happened", nothing more.

- **Master's config was hardcoded; nothing tested it end to end.** Socket
  path, worker bounds, queue size, and timeouts are now constructor
  parameters (defaults unchanged - still each phase's own example values),
  `bin/server.php` honors `WORKER_POOL_SOCKET`, and a new
  `MasterEndToEndTest` boots the real `bin/server.php`, round-trips a real
  request through the SDK, SIGTERMs it, and asserts clean exit plus socket
  file removal. A GitHub Actions workflow runs the suite and PHPStan on
  every push.

---

# Post-Phase-20 Simplification Pass (Fourth)

A follow-up pass with two goals: remove complexity that wasn't earning its
keep, and close the remaining architectural gaps the third pass had only
mitigated pointwise. One more latent bug fell out of the simplification
itself:

- **Queued requests could strand after a burst.** Exposed by rewriting the
  batch-API tests onto the event-driven path (they immediately failed): the
  event path pumped the queue only when a NEW request arrived - a worker
  answering never triggered a dispatch, so a burst followed by silence left
  the queue's tail sitting until request timeout. This is PLAN.md Phase 7's
  own "Worker Response -> dispatch()" event, which the batch API implemented
  (in waitForActivity()) but the event path never did - and Master had used
  the event path since Phase 11, masked by continuous traffic re-pumping the
  queue. Dispatcher now pumps immediately when a worker finishes a request,
  and Master calls the new `Dispatcher::dispatchQueued()` once per tick for
  capacity the Dispatcher can't observe appearing (a crash replacement, a
  scale-up, a reload's fresh generation). The rewritten
  `DispatcherTest::testProcessesMoreRequestsThanWorkersThroughTheQueue`
  (burst of 6 into 2 workers, then ticks only) is the regression test;
  verified live with 12 parallel clients against 2 workers - 12/12 answered.

- **The synchronous batch API is gone.** `Dispatcher::run()` /
  `waitForActivity()` / `UnresolvedRequestsException` /
  `RequestQueue::hasCapacityFor()` were used by nothing but tests - Master
  has been dispatch()+onResponse since Phase 11. One execution model to
  reason about instead of two; the tests that used run() now exercise the
  same scenarios through the event path (which is how the starvation bug
  above surfaced).

- **The SDK no longer returns server errors as results.**
  `WorkerPoolClient::call()` used to hand back an ERROR payload as if the
  request had succeeded - every caller was responsible for remembering to
  check for an 'error' key. It now throws `Sdk\ServerErrorException`
  (carrying the machine-readable code - server_overloaded, request_timeout,
  worker_crashed, handler_failed, server_shutting_down - and the full
  payload).

- **Master restructured: self-pipe for signals, properties over closures,
  Clock injected.** SIGCHLD/SIGHUP/SIGUSR1 handlers now write one
  identifying byte to a self-pipe registered with the same EventLoop; the
  actual work (reap-and-fail, reload, metrics dump) runs from
  drainSignalPipe() in ordinary main-loop context on the very next tick.
  This eliminates the async-signal-reentrancy class of problems structurally
  rather than pointwise: no more forking a new generation from inside signal
  context, no more third-pass lost-request queue (reaping now happens where
  writing to clients is safe, so it just does both). WorkerPool's own
  sigprocmask deferral stays as defense-in-depth for standalone use. The
  wiring itself moved from one long run() with a web of use() closures to
  properties plus named private handlers (handleClientRequest,
  routeResponse, reapCrashedWorkers, ...), and Master takes a Clock like
  the other time-dependent components.

---

# Post-Phase-20: Business Logic Moved to Server Configuration

`WorkerRunner` no longer hardcodes what requests DO. The `calculate` route
(added back in Phase 12) lived inside the runtime; it now lives in
`bin/server.php`, passed into `Master` as an application handler that
`ForkedWorkerLauncher` hands each forked worker (fork() copies parent
memory, so it reaches replacements and scale-ups forked long after startup
too).

**The handler contract is fixed: `Request in, Response out`.** No signature
reflection, no alternative shapes - the runtime hydrates every request
payload into the `Request` envelope (`action` + `params`, what
`WorkerPoolClient::call()` sends) before the call, and the handler answers
with a `Response`:

```php
$handler = static function (Request $request): Response {
    return match ($request->action) {
        'calculate' => Response::of(calculate(PayloadHydrator::hydrate(CalculateRequest::class, $request->params))),
        default => Response::error('unknown_action'),
    };
};
```

An earlier iteration let the handler declare whatever parameter type it
liked and had a `HandlerAdapter` reflect the signature at worker startup to
decide what to pass (raw array vs. hydrated DTO) and how to interpret what
came back (array, DTO, or Response). It worked, but it meant the runtime's
central contract could only be understood by reading the reflection rules -
three input shapes and three output shapes, all implicit. One fixed
signature says the same thing in the type declaration itself, and
`HandlerAdapter` collapsed into `PayloadHydrator`, which now does exactly
one thing.

**`PayloadHydrator`** builds a DTO from a payload array via its
constructor: payload keys matched to parameter names, extra keys ignored,
absent optional parameters falling back to defaults, class-typed parameters
hydrated recursively from nested arrays. It runs twice per request in the
routing pattern above - once by `WorkerRunner` for the envelope, once by the
handler for the matched action's own DTO - so each action stays an ordinary
typed function (`calculate(CalculateRequest): CalculateResult`). A payload
that doesn't fit throws `PayloadHydrationException`, answered as
`invalid_payload`, classified by exception type rather than by where it was
thrown - so an envelope mismatch and an action-DTO mismatch look the same to
a client, and both stay distinct from `handler_failed` (the handler itself
throwing).

**`Worker\Response`** is the outbound counterpart. `Response::of($dto)`
turns a result DTO's JSON-visible state into the response payload;
`new Response([...])` takes a payload directly; `Response::error('unknown_action')`
answers with an ERROR message carrying a code the application chose, which
`WorkerPoolClient` turns back into a `ServerErrorException` whose `->error`
is that same code - previously a handler could only fail by throwing, which
reports `handler_failed` and says nothing about what was wrong.
`$successful` is a bool rather than a `MessageType`: application code
shouldn't need the wire protocol's vocabulary, and `WorkerRunner` stays the
single place that turns a Response into a message, always under the
request's own correlation id, so a handler can't break routing. Its own
failure replies go through the same type. A handler that skips its return
type and hands back something else hits PHP's parameter check inside
`WorkerRunner`'s try - reported as `handler_failed`, worker still alive
(verified live).

**The SDK side takes a DTO too:** `WorkerPoolClient::call()` accepts
`array|object` for its params, so a call site can stay typed end to end -
`$client->call('calculate', new Operands(a: 10, b: 20))`. The object is the
CALLER's own: the two processes share the wire shape, not a class (the
server hydrates those same keys into its own `CalculateRequest`), and a
mismatch comes back as `ServerErrorException('invalid_payload')` - verified
live. The array→object rule now lives once, in `Protocol\\Payload::of()`,
used by both ends that must agree on it: the SDK on the way out and
`Worker\\Response::of()` on the way back.

Covered by `PayloadHydratorTest` (hydration rules, the envelope, rejections)
and `PersistentWorkerTest` (`testPerActionDtoIsHydratedFromParamsAndABadPayloadIsRejected`,
`testHandlerReturnedErrorResponseBecomesAnErrorMessage`,
`testHandlerFailureAnswersWithAnErrorAndTheWorkerSurvives`).

---

# Post-Phase-20: Client-Side Multiplexing

Phase 18 gave the Master multiplexing and left the client synchronous. The
client half exists now, over one connection opened on first use and reused:

```php
$a = $client->send(new Request('calculate', new CalculateRequest(a: 10, b: 20)));
$b = $client->send(new Request('calculate', new CalculateRequest(a: 30, b: 40)));
$c = $client->send(new Request('calculate', new CalculateRequest(a: 50, b: 60)));

[$first, $second, $third] = $client->all($a, $b, $c);
```

`send()` writes the request and returns a `PendingResponse` handle
immediately; `await()` (on the handle or the client) and `all()` are where
this process blocks. `call()` is now just send-then-await, so the
single-request path is unchanged for callers.

Nothing runs in the background and no callback ever fires - the handle is a
claim ticket, not a promise. What it buys is overlap: with `call()` the pool
starts request N+1 only after this process has read answer N. Measured
against a real server with three workers and a handler sleeping 0.5s:
**0.51s for three sends collected together, 1.50s for the same three
call()s** - the difference the Master's multiplexing was always capable of
and the client couldn't use.

Details worth knowing:
- Answers are matched by correlation id and buffered, so they may arrive in
  any order; `all()` returns payloads in the order the handles were passed.
- Each request's timeout runs from when IT was sent, not from when it is
  awaited. A late answer to a request already timed out is discarded, not
  mistaken for another's.
- One failure among several surfaces only on its own handle
  (`ServerErrorException`); the others are unaffected. `all()` throws on the
  first failure, since whoever asked for all of them asked for all to
  succeed - await individually when partial results are worth having.
- A handle is one-shot: collecting it twice throws `LogicException` rather
  than blocking forever.
- A dropped connection clears everything in flight (nothing pending could
  ever arrive) and the next `send()` reconnects.

Covered by `WorkerPoolClientTest` (a forked server that reads every request
before answering any - only possible if the client didn't block on the
first - plus out-of-order collection, error isolation, and double-await) and
`MasterEndToEndTest` against the real server.

---

# Recommended Implementation Order

## MVP

```text
Phase 1  — IPC Foundation
Phase 2  — Persistent Worker
Phase 3  — Message Protocol
Phase 4  — Worker Abstraction
Phase 5  — Worker Pool
Phase 6  — Request Queue
Phase 7  — Dispatcher
```

At this point:

```text
Master
   │
Worker Pool
   │
Request Queue
```

works.

---

## Async Server

```text
Phase 8  — Async Event Loop
Phase 9  — Unix Socket Server
Phase 10 — Client Connections
Phase 11 — Correlation IDs
Phase 12 — PHP Client
```

At this point:

```text
PHP-FPM
CLI
CRON
   │
   ▼
Unix Socket
   │
   ▼
Async Master
   │
   ▼
Worker Pool
```

works.

---

## Production Features

```text
Phase 13 — Backpressure
Phase 14 — Timeouts
Phase 15 — Worker Recovery
Phase 16 — Graceful Shutdown
Phase 17 — Metrics
```

---

## Advanced Features

```text
Phase 18 — Request Multiplexing
Phase 19 — Graceful Reload
Phase 20 — Worker Autoscaling
```

---

# Suggested Project Structure

Original sketch, written before implementation started. Kept below for
history; the actual structure that came out of building the 20 phases is
listed after it — several things moved namespace, merged, or split as the
real requirements (testability with fakes, error types belonging next to
what throws them, ...) became concrete.

```text
src/
│
├── Master/
│   ├── Master.php
│   ├── EventLoop.php
│   ├── Dispatcher.php
│   └── ClientRegistry.php
│
├── Worker/
│   ├── WorkerPool.php
│   ├── WorkerProcess.php
│   ├── WorkerRunner.php
│   └── WorkerState.php
│
├── Protocol/
│   ├── Message.php
│   ├── MessageEncoder.php
│   ├── MessageDecoder.php
│   └── LengthPrefixedProtocol.php
│
├── IPC/
│   └── SocketPair.php
│
├── Queue/
│   └── RequestQueue.php
│
├── Request/
│   ├── Request.php
│   ├── Response.php
│   └── PendingRequest.php
│
├── Client/
│   ├── ClientConnection.php
│   └── WorkerPoolClient.php
│
├── Metrics/
│   └── MetricsRegistry.php
│
└── Support/
    ├── IdGenerator.php
    └── Clock.php
```

## Actual Structure

`EventLoop` and `Dispatcher` ended up as their own top-level namespaces
rather than living under `Master/` - both are used and tested independently
of it. `ClientRegistry`/`ClientConnection`/`PendingRequest(Registry)` stayed
together under `Client/` instead of splitting into a separate `Request/`;
nothing ever needed standalone `Request`/`Response` value objects beyond
`Protocol\Message`. The PHP SDK client (`WorkerPoolClient`) lives under
`Sdk/`, not `Client/`, since it's a consumer of the protocol from outside
the Master process, not part of the Master's own client-handling. Each
namespace holds its own exception types next to what throws them
(`IPC\ConnectionClosedException`, `Protocol\MalformedMessageException`,
`Sdk\ConnectionFailedException`, `Sdk\RequestTimedOutException`,
`Sdk\ServerErrorException`) rather than a shared `Exceptions/` -
`Dispatcher\UnresolvedRequestsException` existed while Dispatcher still had
its synchronous batch API and left with it (fourth pass, below).
`Metrics/MetricsRegistry` became `Metrics/MetricsCollector` (plus
`Metrics.php` and `RequestMetrics.php` for the snapshot value object and the
counters it reads - Phase 17). `Support/IdGenerator` was never built - every
id in this codebase is either a trivial incrementing counter
(`PendingRequestRegistry`) or `uniqid()` (`WorkerPoolClient`), and neither
had an actual problem (collisions, predictability, ...) that would justify
the abstraction. `Support/Clock` was added post-Phase-20, during a code
review - see its own note below.

```text
src/
│
├── Master/
│   └── Master.php
│
├── EventLoop/
│   └── EventLoop.php
│
├── Dispatcher/
│   └── Dispatcher.php
│
├── Worker/
│   ├── WorkerPool.php
│   ├── WorkerProcess.php
│   ├── WorkerRunner.php
│   ├── WorkerState.php
│   ├── WorkerCrash.php
│   ├── WorkerLauncher.php
│   ├── ForkedWorkerLauncher.php
│   ├── Autoscaler.php
│   ├── PayloadHydrator.php
│   └── PayloadHydrationException.php
│
├── Protocol/
│   ├── Message.php
│   ├── MessageType.php
│   ├── MessageEncoder.php
│   ├── MessageDecoder.php
│   ├── Payload.php
│   ├── Request.php
│   ├── Response.php
│   └── MalformedMessageException.php
│
├── Contract/            # the application layer, not the runtime
│   └── Calculate/
│       ├── CalculateAction.php
│       ├── CalculateRequest.php
│       └── CalculateResult.php
│
├── IPC/
│   ├── Socket.php
│   ├── SocketPair.php
│   └── ConnectionClosedException.php
│
├── Server/
│   └── UnixSocketServer.php
│
├── Queue/
│   └── RequestQueue.php
│
├── Client/
│   ├── ClientConnection.php
│   ├── ClientRegistry.php
│   ├── PendingRequest.php
│   └── PendingRequestRegistry.php
│
├── Sdk/
│   ├── WorkerPoolClient.php
│   ├── PendingResponse.php
│   ├── ConnectionFailedException.php
│   ├── RequestTimedOutException.php
│   └── ServerErrorException.php
│
├── Metrics/
│   ├── Metrics.php
│   ├── MetricsCollector.php
│   └── RequestMetrics.php
│
└── Support/
    ├── Clock.php
    ├── SystemClock.php
    ├── Logger.php
    ├── StderrLogger.php
    └── NullLogger.php
```

---

# Development Strategy

Do not build the entire architecture immediately.

Build the system incrementally.

Start with:

```text
step-01
Master
↓
fork()
↓
Worker
↓
Socket Pair
↓
PING / PONG
```

Then:

```text
step-02
Persistent Worker
```

Then:

```text
step-03
Multiple Workers
```

Then:

```text
step-04
Request Queue
```

Then:

```text
step-05
Async Master
```

Then:

```text
step-06
Unix Socket Clients
```

And continue from there.

Every step should:

```text
Compile
Run
Have a demonstration
Have tests where appropriate
```

Do not optimize prematurely.

First make it:

```text
Correct
```

Then:

```text
Reliable
```

Then:

```text
Fast
```

---

# Final Project Goal

The final system should look like this:

```text
                         CLIENTS

          PHP-FPM        CLI        CRON
             │            │           │
             └────────────┼───────────┘
                          │
                    Unix Socket
                          │
                          ▼
             ┌─────────────────────────┐
             │     ASYNC MASTER        │
             │                         │
             │      Event Loop         │
             │                         │
             │   Client Registry       │
             │   Pending Requests      │
             │   Request Queue         │
             │   Dispatcher            │
             │   Worker Supervisor     │
             └────────────┬────────────┘
                          │
              ┌───────────┼───────────┐
              ▼           ▼           ▼
           Worker 1    Worker 2    Worker N
              │           │           │
              └───────────┼───────────┘
                          │
                          ▼
                       Responses
                          │
                          ▼
                        MASTER
                          │
                          ▼
                        CLIENT
```

The final project should demonstrate:

```text
Multi-process architecture
IPC
Unix Domain Sockets
Async I/O
Event Loop
Worker Pools
Request Queues
Message Framing
Correlation IDs
Multiplexing
Backpressure
Timeouts
Process Supervision
Fault Recovery
Graceful Shutdown
Graceful Reload
Metrics
```

---

# Suggested Repository Description

```text
A multi-process asynchronous Worker Pool for PHP using Unix sockets, IPC, persistent worker processes and an event-driven Master process.
```

---

# Learning Goal

The purpose of this project is not to compete with:

```text
RoadRunner
Workerman
Swoole
FrankenPHP
PHP-FPM
```

The purpose is to understand how systems like these work internally by implementing the core mechanisms manually:

```text
Processes
Sockets
IPC
Event Loops
Worker Pools
Request Routing
Process Lifecycle
```

The final result should be a production-inspired educational implementation of a PHP Worker Pool runtime.
