# PHP Worker Pool

> A production-inspired multi-process Worker Pool for PHP built with persistent worker processes, IPC, Unix Domain Sockets, and an asynchronous event-driven Master process.

`php-worker-pool` is an educational project that explores how multi-process PHP runtimes and worker-based architectures work internally.

The project implements a long-running Master process that accepts requests from external PHP applications, delegates work to a pool of persistent workers, and asynchronously routes responses back to clients.

The goal is not to replace existing solutions such as RoadRunner, Workerman, Swoole, or PHP-FPM.

The goal is to understand the underlying concepts by building them from scratch.

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

Possible states:

```text
STARTING
IDLE
BUSY
STOPPING
DEAD
```

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
ping
pong
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

Requests may hang indefinitely.

```text
Request
   │
   ▼
Worker
   │
   ▼
Never Returns
```

Each request can have a deadline.

```text
Created: 10:00:00

Timeout: 5 seconds

Deadline: 10:00:05
```

When the deadline expires, the Master can:

* return a timeout response;
* remove the pending request;
* mark the request as failed;
* optionally terminate the Worker.

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

* [ ] Create socket pair
* [ ] Fork Worker process
* [ ] Send messages from Master to Worker
* [ ] Send responses from Worker to Master

---

## Phase 2 — Persistent Worker

* [ ] Create Worker loop
* [ ] Process multiple requests
* [ ] Return responses
* [ ] Support Worker shutdown

---

## Phase 3 — Message Protocol

* [ ] Implement Message abstraction
* [ ] Implement Encoder
* [ ] Implement Decoder
* [ ] Implement length-prefixed protocol
* [ ] Support partial reads
* [ ] Support multiple messages

---

## Phase 4 — Worker Process

* [ ] Create WorkerProcess abstraction
* [ ] Track Worker PID
* [ ] Track Worker state
* [ ] Track current request

---

## Phase 5 — Worker Pool

* [ ] Start multiple Workers
* [ ] Manage Worker registry
* [ ] Find idle Workers
* [ ] Track busy Workers

---

## Phase 6 — Request Queue

* [ ] Implement RequestQueue
* [ ] Queue requests when Workers are busy
* [ ] Dispatch queued requests

---

## Phase 7 — Dispatcher

* [ ] Connect Queue and Worker Pool
* [ ] Dispatch requests to idle Workers
* [ ] Dispatch automatically after Worker completion

---

## Phase 8 — Async Event Loop

* [ ] Implement Event Loop
* [ ] Use `stream_select()`
* [ ] Handle server socket
* [ ] Handle client sockets
* [ ] Handle Worker IPC sockets

---

## Phase 9 — Unix Domain Socket Server

* [ ] Create Unix Domain Socket server
* [ ] Accept external clients
* [ ] Handle multiple client connections

---

## Phase 10 — Client Connections

* [ ] Create ClientConnection abstraction
* [ ] Handle read buffers
* [ ] Handle write buffers
* [ ] Handle disconnects

---

## Phase 11 — Correlation IDs

* [ ] Generate request IDs
* [ ] Track pending requests
* [ ] Associate requests with clients
* [ ] Route responses to the correct client

---

## Phase 12 — PHP Client

* [ ] Create WorkerPoolClient
* [ ] Support PHP-FPM
* [ ] Support CLI
* [ ] Support synchronous requests
* [ ] Handle connection failures

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

---

## Phase 13 — Backpressure

* [ ] Add maximum queue size
* [ ] Reject requests when overloaded
* [ ] Track rejected requests

---

## Phase 14 — Timeouts

* [ ] Add request deadlines
* [ ] Detect expired requests
* [ ] Return timeout responses

---

## Phase 15 — Worker Recovery

* [ ] Detect Worker crashes
* [ ] Handle SIGCHLD
* [ ] Remove dead Workers
* [ ] Start replacement Workers

---

## Phase 16 — Graceful Shutdown

* [ ] Handle SIGTERM
* [ ] Stop accepting new connections
* [ ] Drain active requests
* [ ] Shutdown Workers gracefully
* [ ] Remove Unix socket

---

## Phase 17 — Metrics

* [ ] Worker metrics
* [ ] Queue metrics
* [ ] Request metrics
* [ ] Request duration
* [ ] Worker processing time

---

## Phase 18 — Request Multiplexing

* [ ] Support multiple requests per connection
* [ ] Support out-of-order responses
* [ ] Support multiple pending requests

Future API:

```php
$request1 = $client->send('task1');
$request2 = $client->send('task2');
$request3 = $client->send('task3');

$response = $request2->await();
```

---

## Phase 19 — Graceful Reload

* [ ] Handle SIGHUP
* [ ] Start new Workers
* [ ] Route new requests to new Workers
* [ ] Drain old Workers
* [ ] Shutdown old Workers

---

## Phase 20 — Worker Autoscaling

* [ ] Configure minimum Workers
* [ ] Configure maximum Workers
* [ ] Scale based on queue size
* [ ] Scale based on Worker utilization

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
