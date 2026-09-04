# PHP Worker Pool

> A production-inspired multi-process Worker Pool for PHP: persistent worker processes, IPC over socket pairs, a Unix domain socket front door, and a single-threaded event-driven Master.

A **PHP runtime engineering playground**: one small, readable
implementation of every mechanism a worker-based runtime is built from, so
that any one of them can be opened and understood on its own.

The goal is not to compete with RoadRunner, Swoole, FrankenPHP or PHP-FPM.
It is to have somewhere to look when you need to remember how process
supervision, message framing, or an event loop actually works - with a
version small enough to read in one sitting and real enough to run, crash,
reload and benchmark.

Production-*inspired*, not production-*ready*: it is a single-node runtime
with at-most-once delivery and a Master that is a single point of failure.
[docs/FAILURE-MODEL.md](docs/FAILURE-MODEL.md) is explicit about every one
of those edges.

```text
  your PHP code ──▶ Unix socket ──▶ Master ──▶ worker pool ──▶ back again
                                    (event loop, queue, supervisor)
```

---

## 30-second demo

Requires Docker. Nothing is installed on your machine.

```bash
make install          # build the image and install dependencies
make run-server       # start the Master (2 workers, grows to 16 under load)
```

In a second terminal:

```bash
make run-client
# {"result":30}
# {"result":30}
# [{"result":30},{"result":70},{"result":110}]
```

That last line is three requests running on three different workers at once.

Then try the things that make it a pool rather than a socket server:

```bash
# how fast is it, really
make bench ARGS="--clients=8 --requests=500"
```

The Master is driven by signals, the way php-fpm and nginx are. `MASTER` below
is its pid - the parent of all the workers:

```bash
MASTER=$(docker compose exec -T php pgrep -o -f "bin/server.php")

# kill one worker outright - a replacement is forked within a second
docker compose exec -T php bash -c "kill -9 \$(pgrep -P $MASTER | head -1)"

# ask the Master how it is doing                     (SIGUSR1)
docker compose exec -T php kill -USR1 $MASTER

# replace every worker without dropping a request    (SIGHUP)
docker compose exec -T php kill -HUP $MASTER

# drain in-flight work, then stop                    (SIGTERM)
docker compose exec -T php kill -TERM $MASTER
```

`pgrep -o` picks the *oldest* match, which is the Master rather than one of
its workers - they all share a command line.

---

## Using it

The server decides what requests mean. That lives in `bin/server.php`, not
in the runtime:

```php
$handler = static function (Request $request): Response {
    return match ($request->action) {
        'calculate' => Response::of(new CalculateAction()(
            PayloadHydrator::hydrate(CalculateRequest::class, $request->params)
        )),
        default => Response::error('unknown_action'),
    };
};

(new Master(handler: $handler))->run();
```

The client is a plain PHP object - usable from PHP-FPM, CLI, cron, or a
queue consumer:

```php
$client = new WorkerPoolClient('/tmp/php-worker-pool.sock');

// one request
$result = $client->call(new Request('calculate', new CalculateRequest(a: 10, b: 20)));

// or several at once, running on separate workers
$a = $client->send(new Request('calculate', new CalculateRequest(a: 1, b: 2)));
$b = $client->send(new Request('calculate', new CalculateRequest(a: 3, b: 4)));
[$first, $second] = $client->all($a, $b);
```

Requests are typed at both ends: the payload is hydrated into the DTO the
action declares, and a payload that doesn't fit comes back as
`ServerErrorException('invalid_payload')` before the action runs.

---

## What it does

| | |
|---|---|
| **Persistent workers** | forked once, reused for every request - no per-request bootstrap |
| **Async Master** | one `stream_select()` over the listener, every client, and every busy worker |
| **Message framing** | length-prefixed frames over a byte stream: partial reads, partial writes, multiple messages per read |
| **Correlation ids** | responses come back in any order and still reach the right caller |
| **Multiplexing** | many requests in flight per connection, client side included |
| **Backpressure** | a bounded queue that rejects instead of growing until OOM |
| **Timeouts** | two of them: a request deadline that answers the client, and an execution limit that kills a handler which will never return |
| **Crash recovery** | SIGCHLD, the dead worker's request failed, a replacement forked |
| **Worker recycling** | replaced after N requests / an age / a memory ceiling - drained, never killed mid-request |
| **Graceful shutdown** | SIGTERM drains in-flight work within one budget, then force-stops |
| **Graceful reload** | SIGHUP swaps the whole generation without dropping a connection |
| **Autoscaling** | grows on queue pressure, shrinks when idle |
| **Metrics** | SIGUSR1 dumps a snapshot, latency split into queue wait vs execution |

---

## The mechanisms, and where to read each one

Every file below is standalone enough to open cold. The comments explain
*why* each decision was made, not what the code does - that is what makes
them worth returning to.

| To remember how… | Open |
|---|---|
| a child process is forked and its file descriptors kept straight | [`IPC/SocketPair.php`](src/IPC/SocketPair.php) · [`Worker/ForkedWorkerLauncher.php`](src/Worker/ForkedWorkerLauncher.php) |
| messages are framed over a byte stream (partial reads, several per read) | [`Protocol/MessageDecoder.php`](src/Protocol/MessageDecoder.php) |
| a partial write is finished instead of silently truncating a frame | [`IPC/Socket.php`](src/IPC/Socket.php) |
| a reactor multiplexes every socket in one `stream_select()` | [`EventLoop/EventLoop.php`](src/EventLoop/EventLoop.php) |
| connections are accepted without capping the rate at one per tick | [`Server/UnixSocketServer.php`](src/Server/UnixSocketServer.php) |
| a slow client is served without blocking everyone else | [`Client/ClientConnection.php`](src/Client/ClientConnection.php) |
| responses find their way back to the right caller, in any order | [`Client/PendingRequestRegistry.php`](src/Client/PendingRequestRegistry.php) |
| backpressure works, and why the queue is bounded | [`Queue/RequestQueue.php`](src/Queue/RequestQueue.php) |
| work is handed to a free worker, and what happens when one dies | [`Dispatcher/Dispatcher.php`](src/Dispatcher/Dispatcher.php) |
| a worker's state machine is written down as one table | [`Worker/WorkerProcess.php`](src/Worker/WorkerProcess.php) |
| crashes, reload, recycling, scaling and shutdown share one owner | [`Worker/WorkerPool.php`](src/Worker/WorkerPool.php) |
| a pool decides to grow or shrink | [`Worker/Autoscaler.php`](src/Worker/Autoscaler.php) |
| a worker is replaced before it leaks, without dropping its request | [`Worker/RecyclingPolicy.php`](src/Worker/RecyclingPolicy.php) |
| signals are handled without doing the work inside the handler | [`Master/Master.php`](src/Master/Master.php) |
| a worker loop stays alive through a handler that throws | [`Worker/WorkerRunner.php`](src/Worker/WorkerRunner.php) |
| a client keeps several requests in flight at once | [`Sdk/WorkerPoolClient.php`](src/Sdk/WorkerPoolClient.php) |

Following a single request through all of them instead:
[docs/REQUEST-LIFECYCLE.md](docs/REQUEST-LIFECYCLE.md).

---

## Documentation

| | |
|---|---|
| **[docs/REQUEST-LIFECYCLE.md](docs/REQUEST-LIFECYCLE.md)** | one request followed hop by hop, client to worker and back, with every failure path |
| **[docs/FAILURE-MODEL.md](docs/FAILURE-MODEL.md)** | what breaks, what survives it, and what the runtime does *not* guarantee - read before trusting it with anything |
| **[docs/BENCHMARKS.md](docs/BENCHMARKS.md)** | measured throughput and latency, where it scales and where it stops |
| **[PLAN.md](PLAN.md)** | the 20 phases this was built in, and why each mechanism exists |
| the rest of this file | the concepts, in depth |

---

## Development

```bash
make test        # PHPUnit
make analyse     # PHPStan level 6
make shell       # a shell in the container
```

Debugging is opt-in, so a run doesn't have a master plus N workers all
reaching for a debugger that isn't there:

```bash
make run-server-debug     # same as run-server, with xdebug active
make run-client-debug
```

Point the IDE at port 9003 first; without a listener the connection attempt
just times out and the run continues.

---

# Architecture

```text
                          CLIENTS

             PHP-FPM        CLI        CRON
                │            │           │
                └────────────┼───────────┘
                             │
                             │
                    Unix Domain Socket
                             │
                             ▼

              ┌─────────────────────────────┐
              │                             │
              │           MASTER            │
              │                             │
              │      Async Event Loop       │
              │                             │
              │  ┌───────────────────────┐  │
              │  │ Client Connections    │  │
              │  └───────────────────────┘  │
              │                             │
              │  ┌───────────────────────┐  │
              │  │ Pending Requests      │  │
              │  └───────────────────────┘  │
              │                             │
              │  ┌───────────────────────┐  │
              │  │ Request Queue         │  │
              │  └───────────────────────┘  │
              │                             │
              │  ┌───────────────────────┐  │
              │  │ Dispatcher            │  │
              │  └───────────────────────┘  │
              │                             │
              │  ┌───────────────────────┐  │
              │  │ Worker Supervisor     │  │
              │  └───────────────────────┘  │
              │                             │
              └──────────────┬──────────────┘
                             │
                       IPC Channels
                             │
             ┌───────────────┼───────────────┐
             │               │               │
             ▼               ▼               ▼

       ┌───────────┐   ┌───────────┐    ┌───────────┐
       │ Worker 1  │   │ Worker 2  │    │ Worker N  │
       │           │   │           │    │           │
       │ Persistent│   │ Persistent│    │ Persistent│
       │ Process   │   │ Process   │    │ Process   │
       └───────────┘   └───────────┘    └───────────┘
```

---

# How It Works

A client sends a request to the Master process through a Unix Domain Socket.

```text
Client
   │
   │ Request
   ▼
Master
```

The Master does not perform heavy work itself.

Instead, it places the request into a queue.

```text
Client
   │
   ▼
Master
   │
   ▼
Request Queue
```

When a worker becomes available, the Dispatcher sends the request to that worker.

```text
Request Queue
       │
       ▼
   Dispatcher
       │
       ▼
   Idle Worker
```

The Worker processes the request and sends a response back to the Master.

```text
Worker
   │
   │ Response
   ▼
Master
```

The Master uses the request correlation ID to find the original client.

```text
Response
   │
   ▼
Request ID
   │
   ▼
Pending Request
   │
   ▼
Client Connection
```

Finally, the response is sent back to the client.

```text
Client
   │
   ▼
Master
   │
   ▼
Worker
   │
   ▼
Master
   │
   ▼
Client
```

---

# Request Flow

```text
┌──────────┐
│  Client  │
└────┬─────┘
     │
     │ Request
     ▼
┌────────────────┐
│     Master     │
└───────┬────────┘
        │
        ▼
┌────────────────┐
│ Request Queue  │
└───────┬────────┘
        │
        ▼
┌────────────────┐
│   Dispatcher   │
└───────┬────────┘
        │
        ▼
┌────────────────┐
│     Worker     │
└───────┬────────┘
        │
        │ Response
        ▼
┌────────────────┐
│     Master     │
└───────┬────────┘
        │
        ▼
┌────────────────┐
│     Client     │
└────────────────┘
```

---

# Core Concepts

This project explores several important Computer Science, Operating System, and Networking concepts.

## Processes

Workers are independent OS processes created using:

```php
pcntl_fork()
```

Each Worker has its own:

* process ID;
* memory space;
* execution context;
* lifecycle.

---

## Persistent Workers

Workers are not created for every request.

Instead, they remain alive and continuously process requests.

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
PROCESS REQUEST
  │
  ▼
SEND RESPONSE
  │
  └──────────────► IDLE
```

This avoids repeatedly creating processes.

---

## IPC

The Master and Workers communicate using Inter-Process Communication.

Initial IPC communication uses:

```text
Socket Pair
```

```text
Master Socket ◄──────────────► Worker Socket
```

---

## Unix Domain Sockets

External clients communicate with the Master through a Unix Domain Socket.

```text
PHP Application
       │
       ▼
/tmp/php-worker-pool.sock
       │
       ▼
Master Process
```

This allows:

* PHP-FPM applications;
* CLI applications;
* cron jobs;
* queue consumers;

to communicate with the same Worker Pool.

---

## Async Event Loop

The Master must handle multiple event sources simultaneously.

```text
                    Event Loop
                        │
         ┌──────────────┼──────────────┐
         │              │              │
         ▼              ▼              ▼

   Server Socket     Clients        Workers
```

The initial implementation uses:

```php
stream_select()
```

The Master reacts to events instead of continuously polling.

---

## Request Queue

When all Workers are busy, incoming requests are placed into a queue.

```text
Requests

Request 1 ──┐
Request 2 ──┤
Request 3 ──┤
Request 4 ──┤
Request 5 ──┘
            │
            ▼

     ┌─────────────┐
     │    Queue    │
     └──────┬──────┘
            │
            ▼

       Idle Worker
```

The initial implementation uses:

```php
SplQueue
```

---

# Worker Lifecycle

Each Worker has a state.

```text
STARTING
    │
    ▼
   IDLE ◀─────────┐
    │             │
    ▼             │
   BUSY ──────────┘
    │
    │  drain()  ── reload, scale-down or recycling:
    ▼             no new work, finish what you have
 DRAINING
    │
    ▼
STOPPING
    │
    ▼
   DEAD
```

Possible states:

```text
STARTING
IDLE
BUSY
DRAINING
STOPPING
DEAD
```

`DRAINING` is what makes graceful reload, scale-down and worker recycling
one mechanism instead of three: the worker takes no new request, but the one
it is already holding is left completely alone and answered normally before
it exits.

---

# Message Protocol

Sockets are streams.

A single read operation does not necessarily return exactly one message.

For example:

```text
{"id":"123","pay
```

may arrive first, followed by:

```text
load":"hello"}
```

Multiple messages can also arrive together.

For this reason, the project uses message framing.

## Length-Prefixed Protocol

```text
┌──────────────┬────────────────────┐
│ Payload Size │ Payload            │
│              │                    │
│   4 bytes    │ N bytes            │
└──────────────┴────────────────────┘
```

Example:

```text
[00000042][{"type":"request","id":"123"}]
```

The protocol must support:

* partial reads;
* multiple messages in a single read;
* large messages;
* malformed messages.

---

# Message Types

The protocol supports several message types.

```text
request
response
error
shutdown
```

Example request:

```json
{
    "type": "request",
    "id": "request-123",
    "payload": {
        "action": "calculate",
        "a": 10,
        "b": 20
    }
}
```

Example response:

```json
{
    "type": "response",
    "id": "request-123",
    "payload": {
        "result": 30
    }
}
```

---

# Correlation IDs

Workers can complete requests in any order.

```text
Client A → Request #1
Client B → Request #2
Client C → Request #3
```

Workers may finish like this:

```text
Response #2
Response #3
Response #1
```

Every request therefore has a unique correlation ID.

```text
Request
   │
   ▼
ID: abc-123
   │
   ▼
Worker
   │
   ▼
Response
   │
   ▼
ID: abc-123
```

The Master keeps a registry of pending requests.

```text
Request ID
    │
    ▼
Client Connection
```

This allows responses to be routed back to the correct client.

---

# Backpressure

A Worker Pool must protect itself from overload.

Without limits:

```text
Incoming Requests
        │
        ▼
   Infinite Queue
        │
        ▼
 Memory Growth
        │
        ▼
       OOM
        │
        ▼
      Crash
```

The Worker Pool will support a maximum queue size.

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

Example error:

```json
{
    "type": "error",
    "error": "server_overloaded"
}
```

---

# Timeouts

A request that never comes back is two problems, not one, and they need
separate answers.

```text
Request
   │
   ▼
Worker
   │
   ▼
Never returns
   │
   ├──▶ problem 1: someone is waiting
   │
   └──▶ problem 2: a worker slot is occupied
```

## Request timeout - about the client

The caller stops waiting.

```text
Created:  10:00:00
Timeout:  30 seconds        (requestTimeoutSeconds)
Deadline: 10:00:30
              │
              ▼
   {"type":"error","payload":{"error":"request_timeout"}}
              │
              ▼
   the pending request is removed and counted
```

Swept once a second, so a deadline is noticed within a second of passing.
The worker is deliberately left alone here: it might be one millisecond from
answering, and killing it would turn a slow request into a lost one.

## Execution timeout - about the pool

The worker is not slow, it is never finishing.

```text
Dispatched: 10:00:00
Limit:      60 seconds      (workerExecutionTimeoutSeconds)
                │
                ▼
   SIGTERM ──▶ (still alive on the next sweep?) ──▶ SIGKILL
                │
                ▼
   SIGCHLD ──▶ reaped ──▶ replacement forked
                │
                ▼
   counted as a termination, not a crash
```

Without this, a handler stuck in a loop would hold its worker forever -
costing the pool one slot permanently, per stuck request, until nothing is
left to serve with.

The two limits are separate numbers on purpose, and the execution limit sits
above the request timeout: by the time it fires, the client left long ago
and the question is no longer "is this late?" but "is this ever coming
back?". A draining worker is exempt - it is already leaving, and its last
request is finishing normally.

---

# Worker Supervision

Workers can crash.

```text
Worker #3
   │
   ▼
Processing Request
   │
   ▼
   💀
```

The Master must detect the failure.

```text
Worker Dies
     │
     ▼
Detect Failure
     │
     ▼
Remove Worker
     │
     ▼
Handle Active Request
     │
     ▼
Start Replacement Worker
```

Possible detection mechanisms:

```text
SIGCHLD
Closed IPC Socket
waitpid()
```

---

# Graceful Shutdown

The Worker Pool should support graceful shutdown.

```text
SIGTERM
   │
   ▼
Stop Accepting New Connections
   │
   ▼
Stop Accepting New Requests
   │
   ▼
Finish Running Work
   │
   ▼
Shutdown Workers
   │
   ▼
Wait For Workers
   │
   ▼
Remove Unix Socket
   │
   ▼
Exit
```

A timeout prevents shutdown from hanging forever.

```text
gracefulShutdownTimeout = 30 seconds
```

---

# Project Structure

```text
php-worker-pool/
│
├── bin/
│   ├── server.php
│   └── client.php
│
├── src/
│   │
│   ├── Master/
│   │   ├── Master.php
│   │   ├── EventLoop.php
│   │   ├── Dispatcher.php
│   │   └── ClientRegistry.php
│   │
│   ├── Worker/
│   │   ├── WorkerPool.php
│   │   ├── WorkerProcess.php
│   │   ├── WorkerRunner.php
│   │   └── WorkerState.php
│   │
│   ├── Protocol/
│   │   ├── Message.php
│   │   ├── MessageEncoder.php
│   │   ├── MessageDecoder.php
│   │   └── LengthPrefixedProtocol.php
│   │
│   ├── IPC/
│   │   └── SocketPair.php
│   │
│   ├── Queue/
│   │   └── RequestQueue.php
│   │
│   ├── Request/
│   │   ├── Request.php
│   │   ├── Response.php
│   │   └── PendingRequest.php
│   │
│   ├── Client/
│   │   ├── ClientConnection.php
│   │   └── WorkerPoolClient.php
│   │
│   ├── Metrics/
│   │   └── MetricsRegistry.php
│   │
│   └── Support/
│       ├── IdGenerator.php
│       └── Clock.php
│
├── tests/
│
├── composer.json
├── README.md
└── PLAN.md
```

---

# Roadmap

## Phase 1 — IPC Foundation

* [x] Create socket pair
* [x] Fork Worker process
* [x] Send messages from Master to Worker
* [x] Send responses from Worker to Master

---

## Phase 2 — Persistent Worker

* [x] Create Worker loop
* [x] Process multiple requests
* [x] Return responses
* [x] Support Worker shutdown

---

## Phase 3 — Message Protocol

* [x] Implement Message abstraction
* [x] Implement Encoder
* [x] Implement Decoder
* [x] Implement length-prefixed protocol
* [x] Support partial reads
* [x] Support multiple messages

---

## Phase 4 — Worker Process

* [x] Create WorkerProcess abstraction
* [x] Track Worker PID
* [x] Track Worker state
* [x] Track current request

---

## Phase 5 — Worker Pool

* [x] Start multiple Workers
* [x] Manage Worker registry
* [x] Find idle Workers
* [x] Track busy Workers

---

## Phase 6 — Request Queue

* [x] Implement RequestQueue
* [x] Queue requests when Workers are busy
* [x] Dispatch queued requests

---

## Phase 7 — Dispatcher

* [x] Connect Queue and Worker Pool
* [x] Dispatch requests to idle Workers
* [x] Dispatch automatically after Worker completion

---

## Phase 8 — Async Event Loop

* [x] Implement Event Loop
* [x] Use `stream_select()`
* [x] Handle server socket
* [x] Handle client sockets
* [x] Handle Worker IPC sockets

---

## Phase 9 — Unix Domain Socket Server

* [x] Create Unix Domain Socket server
* [x] Accept external clients
* [x] Handle multiple client connections

---

## Phase 10 — Client Connections

* [x] Create ClientConnection abstraction
* [x] Handle read buffers
* [x] Handle write buffers
* [x] Handle disconnects

---

## Phase 11 — Correlation IDs

* [x] Generate request IDs
* [x] Track pending requests
* [x] Associate requests with clients
* [x] Route responses to the correct client

---

## Phase 12 — PHP Client

* [x] Create WorkerPoolClient
* [x] Support PHP-FPM
* [x] Support CLI
* [x] Support synchronous requests
* [x] Handle connection failures

Example API:

```php
$client = new WorkerPoolClient('/tmp/php-worker-pool.sock');

$response = $client->call(
    new Request('calculate', new CalculateRequest(a: 10, b: 20))
);
```

`call()` takes the same `Request` envelope the worker's handler receives, so
both ends speak in the same terms; its params may be a plain array or a DTO.
A server that answers with an error throws `ServerErrorException` rather
than returning the error payload as if it were a result.

---

## Phase 13 — Backpressure

* [x] Add maximum queue size
* [x] Reject requests when overloaded
* [x] Track rejected requests

---

## Phase 14 — Timeouts

* [x] Add request deadlines
* [x] Detect expired requests
* [x] Return timeout responses

---

## Phase 15 — Worker Recovery

* [x] Detect Worker crashes
* [x] Handle SIGCHLD
* [x] Remove dead Workers
* [x] Start replacement Workers

---

## Phase 16 — Graceful Shutdown

* [x] Handle SIGTERM
* [x] Stop accepting new connections
* [x] Drain active requests
* [x] Shutdown Workers gracefully
* [x] Remove Unix socket

---

## Phase 17 — Metrics

* [x] Worker metrics
* [x] Queue metrics
* [x] Request metrics
* [x] Request duration
* [x] Worker processing time

Reported as a breakdown rather than one number, because one number can't
tell a saturated pool from a slow handler. Same 20ms handler, same clients,
different pool size:

```text
8 workers, 8 clients          1 worker, 8 clients
  Queue wait: avg   0.03ms      Queue wait: avg 139.72ms   ← the pool
  Execution:  avg  21.50ms      Execution:  avg  20.94ms   ← the handler
  Total:      avg  21.53ms      Total:      avg 160.66ms
```

---

## Phase 18 — Request Multiplexing

* [x] Support multiple requests per connection
* [x] Support out-of-order responses
* [x] Support multiple pending requests

Client API (this was a sketch when the phase was written; it exists now):

```php
$first  = $client->send(new Request('calculate', new CalculateRequest(a: 10, b: 20)));
$second = $client->send(new Request('calculate', new CalculateRequest(a: 30, b: 40)));
$third  = $client->send(new Request('calculate', new CalculateRequest(a: 50, b: 60)));

// collect them together...
[$a, $b, $c] = $client->all($first, $second, $third);

// ...or one at a time, in any order
$b = $second->await();
```

`send()` writes the request out and returns immediately, so all three occupy
workers at once - measured at 0.51s against 1.50s for the same three
`call()`s with a handler sleeping 0.5s.

---

## Phase 19 — Graceful Reload

* [x] Handle SIGHUP
* [x] Start new Workers
* [x] Route new requests to new Workers
* [x] Drain old Workers
* [x] Shutdown old Workers

---

## Phase 20 — Worker Autoscaling

* [x] Configure minimum Workers
* [x] Configure maximum Workers
* [x] Scale based on queue size
* [x] Scale based on Worker utilization

---

# Development Strategy

The project should be implemented incrementally.

Start simple.

```text
Step 1

Master
   │
fork()
   │
Worker
   │
Socket Pair
   │
PING / PONG
```

Then:

```text
Step 2

Persistent Worker
```

```text
Step 3

Multiple Workers
```

```text
Step 4

Request Queue
```

```text
Step 5

Dispatcher
```

```text
Step 6

Async Event Loop
```

```text
Step 7

Unix Socket Server
```

```text
Step 8

External PHP Client
```

After the MVP works, add production-inspired features.

---

# Development Philosophy

The implementation order should be:

```text
1. Make it work
        │
        ▼
2. Make it correct
        │
        ▼
3. Make it reliable
        │
        ▼
4. Make it observable
        │
        ▼
5. Make it fast
```

Avoid premature optimization.

The purpose of the project is understanding.

---

# What This Project Is Not

This project is not intended to replace:

* RoadRunner;
* Workerman;
* Swoole;
* FrankenPHP;
* PHP-FPM.

Production systems solve many additional problems:

```text
HTTP protocol handling
TLS
Security
Process management
Memory management
Resource isolation
Advanced networking
Production monitoring
Configuration management
Cross-platform support
```

This project focuses on understanding the core mechanisms behind multi-process worker architectures.

---

# Concepts Explored

## Computer Science

* Worker Pools
* Queues
* Backpressure
* State Machines
* Message Passing
* Correlation IDs
* Scheduling
* Multiplexing

## Operating Systems

* Processes
* `fork()`
* Signals
* IPC
* Process lifecycle
* File descriptors
* Unix Domain Sockets

## Networking

* Sockets
* Stream-based protocols
* Message framing
* Partial reads
* Partial writes
* Connection management
* Event-driven I/O

## Concurrency

* Multi-process architecture
* Event loops
* Asynchronous I/O
* Worker supervision
* Request routing
* Process pools

---

# Final Goal

The final system should demonstrate how the following components work together:

```text
                        CLIENTS

                  PHP-FPM / CLI / CRON
                           │
                           ▼

                    Unix Domain Socket
                           │
                           ▼

                  ┌─────────────────┐
                  │  ASYNC MASTER   │
                  │                 │
                  │   Event Loop    │
                  │                 │
                  │ Request Queue   │
                  │ Dispatcher      │
                  │ Client Registry │
                  │ Worker Manager  │
                  └────────┬────────┘
                           │
                           ▼

                     PROCESS POOL

                  ┌───────────────┐
                  │ Worker Pool   │
                  └───────┬───────┘
                          │
             ┌────────────┼────────────┐
             ▼            ▼            ▼

         Worker 1      Worker 2      Worker N
```

The project combines:

```text
Processes
IPC
Unix Sockets
Async I/O
Event Loops
Worker Pools
Queues
Request Routing
Backpressure
Timeouts
Fault Recovery
Graceful Shutdown
Metrics
```

---

# Learning Objective

The primary goal of this repository is to answer one question:

> How can a long-running PHP Master process accept requests asynchronously and delegate work to persistent Worker processes?

By implementing the system step by step, the project explores how worker-based runtimes and multi-process architectures operate internally.

---

## License

MIT
