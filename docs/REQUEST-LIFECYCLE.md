# The Life of a Request

One request, followed from the calling process all the way to a worker and
back, with what actually happens at every hop. Everything below is the code
as it stands - class and method names are real, and so are the constants.

Companion to [PHASES.md](PHASES.md), which explains *why* each mechanism
exists phase by phase. This document is the *how*, end to end, in one pass.

---

## The processes involved

Three kinds of process, two kinds of socket. Nothing here is threaded: the
Master is single-threaded and never blocks on application work; the workers
block freely, because that is all they do.

```text
  ┌──────────────────┐                  ┌──────────────┐      ┌──────────────────┐
  │ WorkerPoolClient │                  │              │      │  WorkerRunner    │
  │                  │                  │    Master    │      │                  │
  │  send()          │═════════════════▶│              │═════▶│  blocking read   │
  │  await()         │                  │              │      │  handler         │
  │  all()           │◀═════════════════│              │◀═════│  write back      │
  └──────────────────┘                  └──────────────┘      └──────────────────┘

                       Unix domain socket                socketpair(AF_UNIX),
                       /tmp/php-worker-pool.sock         one per worker -
                                               ▲         never a file
                                               │
                                         ┌─────┴──────┐
                                         │  EventLoop │  one stream_select() over:
                                         │            │  the listener + every client socket
                                         │            │  + every BUSY worker + the self-pipe
                                         └────────────┘
```

Both socket kinds carry the exact same framing and the exact same `Message`
type - the only difference is who is on the other end.

---

## The wire format

Every message, in both directions, on both socket kinds:

```text
┌──────────────────┬────────────────────────────────────────────┐
│  4 bytes         │  N bytes                                   │
│  big-endian uint │  JSON                                      │
│  = N             │                                            │
└──────────────────┴────────────────────────────────────────────┘

  pack('N', strlen($json)) . $json          MessageEncoder::encode()
```

The JSON is always a `Message`: a type, a correlation id, a payload.

```json
{"type":"request","id":"req-68b2...","payload":{"action":"calculate","params":{"a":10,"b":20}}}
```

Framing exists because a socket is a byte stream, not a message stream. One
`fread()` may return half a frame, or three frames, or one and a half.
`MessageDecoder` keeps a buffer and hands back only whole messages - zero,
one, or several per read. A frame claiming more than **1 MiB**
(`maxMessageSize`) is rejected as malformed rather than trusted.

Four message types exist: `request`, `response`, `error`, `shutdown`.

---

## The happy path, step by step

Following `$client->send(new Request('calculate', new CalculateRequest(a: 10, b: 20)))`
through to its answer.

```text
  ①  CLIENT: build and write
  ─────────────────────────────────────────────────────────────────────────
      Request('calculate', CalculateRequest(a: 10, b: 20))
          │  Request::__construct
          │    Payload::of($params)  ── an object becomes its JSON-visible
          │                             state; an array passes through
          ▼
      payload = {action: 'calculate', params: {a: 10, b: 20}}
          │
          │  id = uniqid('req-', true)      ← the CLIENT's correlation id
          │  Message(REQUEST, id, payload)
          │  MessageEncoder::encode         ← [len][json]
          ▼
      Socket::write ──▶ /tmp/php-worker-pool.sock
          │
          │  deadlines[id] = now + 5.0s     ← this request's own deadline,
          │                                    running from HERE, not from
          ▼                                    when it is awaited
      returns PendingResponse immediately   ← nothing blocks yet


  ②  MASTER: accept the connection            (first request on it only)
  ─────────────────────────────────────────────────────────────────────────
      EventLoop::tick() ── stream_select() reports the LISTENER readable
          │
          ▼
      UnixSocketServer::accept()
          │  stream_socket_accept(..., 0)
          │  stream_set_blocking($client, false)   ← never block the Master
          ▼
      ClientRegistry::accept()
          │  new ClientConnection(new Socket($fd), $loop)
          │  id = (int) $fd                  ← stable while the fd is open
          ▼
      loop->addReadable($fd, <read handler>)  ← now multiplexed with
                                                 everything else


  ③  MASTER: read and decode
  ─────────────────────────────────────────────────────────────────────────
      EventLoop::tick() ── that client socket is readable
          │
          ▼
      ClientConnection::readAvailable()
          │  fread($fd, 8192)
          │  MessageDecoder::decode($chunk)  ← may yield 0, 1 or many
          ▼                                     (partials stay buffered)
      for each decoded Message → Master::handleClientRequest($client, $msg)

      ┌ EOF or unparseable bytes here? ──────────────────────────────────┐
      │  ConnectionClosedException | MalformedMessageException           │
      │    → ClientRegistry::remove(): deregister, close, onDisconnect   │
      │    → PendingRequestRegistry::removeByClient() drops that         │
      │      client's in-flight entries - nobody left to answer          │
      │  The Master itself is never taken down by a bad client.          │
      └──────────────────────────────────────────────────────────────────┘


  ④  MASTER: register the pending request
  ─────────────────────────────────────────────────────────────────────────
      requestMetrics->recordReceived()               requests_total++
          │
          ▼
      PendingRequestRegistry::register($client, $clientsOwnId, 30.0s)
          │
          │  dispatchId = 'req-' . $nextId++     ← the MASTER's own id,
          │                                         never the client's
          │  pending[dispatchId] = PendingRequest(
          │      client,                         ← where the answer goes
          │      originalId = $clientsOwnId,     ← restored on the way out
          │      deadline   = now + 30.0s )
          ▼
      returns dispatchId

      Why a second id: two different clients - or one client by mistake -
      can pick the same correlation id. The Master is the one place that
      would silently misroute an answer if that happened, so it keys by an
      id it controls and hands the client's own id back at the end.


  ⑤  MASTER: dispatch
  ─────────────────────────────────────────────────────────────────────────
      Dispatcher::dispatch(Message(REQUEST, dispatchId, payload))
          │
          ├─ queue->isFull()?  (maxQueueSize = 10 000)
          │     yes → recordRejection(); return false
          │           → Master answers ERROR {"error":"server_overloaded"}
          │             under the client's own id, and drops the pending
          │             entry - backpressure, not an unbounded queue
          │
          ├─ queue->enqueue($message)
          ▼
      Dispatcher::pump()
          │
          │  while the queue is not empty:
          │      workerId = pool->getAvailable()
          │          └─ first worker that is IDLE,
          │             skipping BUSY, DRAINING, STOPPING and DEAD ones
          │
          │      none available? → return, leave the rest queued
          │                        (a worker finishing will pump again)
          ▼
      pool->write($workerId, $message)
          │  ┌ SIGCHLD deferred for this whole block (pcntl_sigprocmask) ┐
          │  │  re-check the worker still exists and is available -      │
          │  │  the reaper may have removed it since getAvailable().     │
          │  │  Gone? return null → pump() requeues and picks another.   │
          │  └───────────────────────────────────────────────────────────┘
          │  worker->write($message)      ← same [len][json] framing
          │  worker->beginRequest(dispatchId)   IDLE ──▶ BUSY
          ▼
      Dispatcher::watch($worker)
          │
          ▼
      loop->addReadable($workerSocket, <response handler>)
          ← registered only while BUSY; removed again when it answers


  ⑥  WORKER: handle it
  ─────────────────────────────────────────────────────────────────────────
      WorkerRunner::run() is sitting in a BLOCKING Socket::read()
          │
          ▼
      decoded Message
          │
          ├─ type is SHUTDOWN? → close the socket, exit(0)
          │
          ▼
      PayloadHydrator::hydrate(Request::class, $payload)
          │      keys matched to constructor parameter names,
          │      extra keys ignored, defaults applied when absent,
          │      class-typed parameters hydrated recursively
          ▼
      Request(action: 'calculate', params: [a => 10, b => 20])
          │
          ▼
      the application handler        ← bin/server.php, injected at startup
          │                             and inherited by every worker
          │                             through fork()
          │  match ($request->action) {
          │      'calculate' => Response::of(
          │           (new CalculateAction())(
          │               PayloadHydrator::hydrate(
          │                   CalculateRequest::class, $request->params)));
          │      default => Response::error('unknown_action');
          │  }
          ▼
      Response(payload: {result: 30}, successful: true)
          │
          ▼
      WorkerRunner::toMessage($request->id, $response)
          │  successful → RESPONSE, otherwise → ERROR
          │  ALWAYS under the id the request arrived with
          ▼
      write back over the socketpair

      ┌ Three ways this step can fail, all answered, none fatal ─────────┐
      │  payload doesn't fit a DTO → PayloadHydrationException           │
      │                              → ERROR {"error":"invalid_payload"} │
      │  handler threw              → ERROR {"error":"handler_failed"}   │
      │  handler chose to fail      → ERROR {"error":"<its own code>"}   │
      │  In every case the worker stays alive and serves the next        │
      │  request - a bad request never costs a fork.                     │
      └──────────────────────────────────────────────────────────────────┘


  ⑦  MASTER: collect the answer
  ─────────────────────────────────────────────────────────────────────────
      EventLoop::tick() ── that worker's socket is readable
          │
          ▼
      the handler Dispatcher::watch() registered
          │
          │  worker->readAvailable()
          │  is this the id the worker is BUSY with?
          │      yes → worker->finishRequest()      BUSY ──▶ IDLE
          │            loop->removeReadable($workerSocket)
          │      no  → (partial frame) leave it registered, read again later
          ▼
      onResponse → Master::routeResponse($message)
          │
          │  pending = PendingRequestRegistry::resolve($message->id)
          │      one-shot: the entry is removed as it is read, so a
          │      duplicate or late answer finds nothing and is dropped
          │
          │  RESPONSE → requests_completed++    other → requests_failed++
          ▼
      pending->client->write(
          Message($type, $pending->originalId, $payload) )
          │                 ▲
          │                 └─ the CLIENT's own id is restored here
          ▼
      Dispatcher::pump()      ← the worker just went idle; hand it the
                                 next queued request immediately


  ⑧  MASTER: write it out without blocking
  ─────────────────────────────────────────────────────────────────────────
      ClientConnection::write()
          │  append the encoded frame to this connection's write buffer
          │
          ├─ buffer over 4 MiB? → the client isn't draining; declare it
          │                       broken, drop the buffer, half-close.
          │                       (outbound backpressure - the Master's
          │                       memory is not a client's problem)
          ▼
      flush(): Socket::writeChunk() - ONE non-blocking attempt
          │
          ├─ all bytes accepted → done
          │
          └─ partial (kernel send buffer full)
                 │  keep the remainder
                 │  loop->addWritable($fd, flush(...))
                 ▼
             the next tick that finds the socket writable sends more,
             and deregisters once the buffer drains

      Why not just write and block: the Master is single-threaded. One slow
      reader blocking a write would stall every other client and worker for
      the duration.


  ⑨  CLIENT: collect
  ─────────────────────────────────────────────────────────────────────────
      $pending->await()   (or $client->all(...))
          │
          │  loop until this id has arrived:
          │      remaining = deadlines[id] - now
          │      remaining <= 0 → RequestTimedOutException
          │
          │      Socket::readAvailable($remaining)
          │          │
          │          ▼
          │      for each decoded Message:
          │          ours?  → arrived[id] = message      ← buffered, even
          │                                                 if nobody is
          │                                                 waiting on it yet
          │          not ours? → discarded (a late answer to something
          │                      already given up on)
          ▼
      the message for this id
          │
          ├─ type is ERROR → throw ServerErrorException($code, $payload)
          │
          ▼
      return $message->payload        →  ['result' => 30]
```

---

## Several at once

`send()` is what makes the pool worth having. Three sends occupy three
workers; three `call()`s occupy one worker three times.

```text
  call() x3                            send() x3 + all()

  t  client        pool               t  client        pool
  │                                   │
  0  write #1  ──▶ worker A busy      0  write #1  ──▶ worker A busy
  │  (blocked)                        │  write #2  ──▶ worker B busy
  │                                   │  write #3  ──▶ worker C busy
  0.5 ◀── #1       A idle             │  (blocked in all())
  │  write #2  ──▶ worker A busy      │
  │  (blocked)                        │
  1.0 ◀── #2       A idle             0.5 ◀── #2, #3, #1   all idle
  │  write #3  ──▶ worker A busy      │
  │  (blocked)                        │
  1.5 ◀── #3                          │

  total 1.50s                         total 0.51s
```

Measured, not estimated: three workers, a handler sleeping 0.5s.

Answers are matched by correlation id and buffered as they land, so they may
come back in any order; `all()` returns payloads in the order the handles
were passed, whatever order they actually arrived in.

---

## Everything that can go differently

Each of these is a real path with a real error code on the wire. A client
sees them all the same way: `ServerErrorException`, with `->error` carrying
the code.

```text
  WHERE                WHEN                          THE CLIENT GETS
  ───────────────────────────────────────────────────────────────────────────
  Dispatcher           queue is at maxQueueSize      error server_overloaded
   ⑤                   (10 000 waiting)

  WorkerRunner         payload doesn't fit the       error invalid_payload
   ⑥                   Request envelope, or an
                       action's own DTO

  WorkerRunner         the handler threw             error handler_failed
   ⑥

  the handler          it chose to fail              error <its own code>
   ⑥                   (Response::error(...))          e.g. unknown_action

  Master main loop     no answer within 30s          error request_timeout
   (per tick)          (PendingRequestRegistry::
                        removeExpired, swept once
                        a second)

  Master main loop     one worker has held ONE       (client already got
   (per tick)          request past the execution     request_timeout; the
                       limit, 60s - it is killed      worker is killed so the
                       and replaced                   pool gets its slot back)

  Dispatcher / SIGCHLD the worker died holding       error worker_crashed
                       the request

  Master shutdown      still pending when the        error server_shutting_down
                       30s drain window closed

  the client itself    no answer within its own      RequestTimedOutException
   ⑨                   timeoutSeconds (5s default)     (thrown locally, the
                                                        server was never told)
```

---

## What the Master does besides forwarding

The main loop is not just `tick()`. Every pass, after the loop returns
(at most 1s later even with no traffic at all, so none of these can be
starved by silence):

```text
  while ($this->running) {

      $loop->tick(1.0);                ← the only place this process blocks
                                          (stream_select over every fd)

      $dispatcher->dispatchQueued();   ← capacity can appear where the
                                          Dispatcher can't see it: a crash
                                          replacement, a scale-up, a fresh
                                          reload generation

      $this->sendTimeouts();           ← PendingRequestRegistry::removeExpired
                                          → error request_timeout per entry

      $pool->terminateStuckWorkers(..);← a worker that has held ONE request past
                                          the execution limit is killed and
                                          replaced: the client gave up long ago,
                                          but the slot is still occupied

      $pool->recycleExhaustedWorkers();← a worker past maxRequests, maxLifetime
                                          or maxMemory is DRAINED and replaced;
                                          if it is mid-request it keeps it and
                                          answers normally first

      $pool->retireIdleWorkers();      ← a draining worker that has stopped
                                          working gets SHUTDOWN and its socket
                                          closed

      $autoscaler->check();            ← queue busy and nothing idle → +2
                                          queue empty and idle to spare → -2
                                          bounded [2, 16], 5s cooldown
  }
```

### Signals never do the work themselves

`pcntl_async_signals(true)` means a handler body can run between any two
statements of whatever it interrupted. So the handlers do the smallest
async-signal-safe thing there is - write one byte to a pipe the same
EventLoop is watching - and the actual work happens in the next tick, in
ordinary sequential code.

```text
   SIGCHLD ──▶ write 'C' ──┐
   SIGHUP  ──▶ write 'H' ──┼──▶ self-pipe ──▶ EventLoop (readable)
   SIGUSR1 ──▶ write 'U' ──┘                       │
                                                   ▼
                                        Master::drainSignalPipe()
                                            │
        'C' → reapDeadWorkers(): waitpid(WNOHANG) every exited child,
              replace each crashed one, and fail the request it was
              holding with worker_crashed
        'H' → pool->reload(): start a fresh generation, mark the old one
              DRAINING; a busy old worker is left completely alone
              until it finishes - no in-flight request is ever dropped
        'U' → print the metrics snapshot to stdout

   SIGINT / SIGTERM ──▶ set $running = false     ← the loop notices on the
                                                    tick the signal interrupts
```

Writing to a client from inside a signal handler would be the dangerous
version of this: it could land in the middle of a buffered write already in
progress on that very connection.

### Shutdown

```text
   SIGTERM
      │
      ▼
   stop accepting: UnixSocketServer::close() - the listener leaves the loop
      │              and the socket file is unlinked
      ▼
   drain: keep ticking the SAME loop while requests are still pending,
      │   up to 30s total. Workers and connected clients notice nothing;
      │   in-flight work finishes and is delivered normally.
      ▼
   give up on whoever is left: error server_shutting_down (told, not dropped)
      │
      ▼
   flush: keep ticking until every client write buffer is empty, so those
      │   last frames actually leave the process
      ▼
   stop the workers: SHUTDOWN to each, wait for exits within whatever is
      │              left of the same 30s budget, then SIGKILL the stragglers
      ▼
   exit
```

The 30 seconds is one end-to-end allowance, not 30s of draining plus a
separate 30s of waiting for workers.

---

## Where each piece lives

```text
  ①  ⑨   src/Sdk/WorkerPoolClient.php, PendingResponse.php
  ①      src/Protocol/Request.php, Payload.php
  wire   src/Protocol/Message.php, MessageEncoder.php, MessageDecoder.php
         src/IPC/Socket.php            framing, partial reads, partial writes
  ②      src/Server/UnixSocketServer.php
  ②③⑧   src/Client/ClientRegistry.php, ClientConnection.php
  ④⑦    src/Client/PendingRequestRegistry.php, PendingRequest.php
  ⑤⑦    src/Dispatcher/Dispatcher.php, src/Queue/RequestQueue.php
  ⑤⑦    src/Worker/WorkerPool.php, WorkerProcess.php, WorkerState.php
  ⑥      src/Worker/Runtime/WorkerRunner.php, PayloadHydrator.php
         src/Protocol/Response.php
  ⑥      src/Contract/Calculate/        the application, not the runtime
  all    src/Master/Master.php          the wiring and the main loop
         src/EventLoop/EventLoop.php    the one stream_select()
```
