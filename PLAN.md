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

* [ ] Create a socket pair
* [ ] Fork a worker process
* [ ] Close unnecessary socket descriptors
* [ ] Send a message from Master to Worker
* [ ] Read the message in Worker
* [ ] Send a response from Worker to Master
* [ ] Read the response in Master

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

* [ ] Create Worker loop
* [ ] Receive multiple requests
* [ ] Process requests
* [ ] Return responses
* [ ] Support shutdown command

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

* [ ] Create Message class
* [ ] Create Encoder
* [ ] Create Decoder
* [ ] Implement length-prefixed messages
* [ ] Support partial reads
* [ ] Support multiple messages in one read
* [ ] Add malformed message handling

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

* [ ] partial messages
* [ ] multiple messages
* [ ] large messages
* [ ] malformed messages

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

* [ ] Create WorkerProcess class
* [ ] Store PID
* [ ] Store IPC socket
* [ ] Store worker state
* [ ] Store current request ID
* [ ] Implement state transitions

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

* [ ] Configure worker count
* [ ] Start N workers
* [ ] Create IPC channel for each worker
* [ ] Register workers
* [ ] Track worker state
* [ ] Find an available worker

## Initial Configuration

```php
$workerCount = 4;
```

## Definition of Done

Four Workers can process four requests simultaneously.

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

* [ ] Create RequestQueue abstraction
* [ ] Add enqueue()
* [ ] Add dequeue()
* [ ] Add size()
* [ ] Add empty check

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

* [ ] Create Dispatcher
* [ ] Find idle worker
* [ ] Take request from queue
* [ ] Send request to worker
* [ ] Mark worker as BUSY
* [ ] Dispatch next request when worker becomes IDLE

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

* [ ] Create EventLoop
* [ ] Register readable sockets
* [ ] Run stream_select()
* [ ] Detect event source
* [ ] Handle client events
* [ ] Handle worker events

## Definition of Done

The Master can simultaneously handle multiple clients and workers.

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

* [ ] Create Unix Domain Socket
* [ ] Start socket server
* [ ] Accept connections
* [ ] Set sockets to non-blocking mode
* [ ] Remove socket file during shutdown
* [ ] Handle stale socket file on startup

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

* [ ] Create ClientConnection class
* [ ] Store socket
* [ ] Store read buffer
* [ ] Store write buffer
* [ ] Handle disconnect
* [ ] Remove dead clients

## Important

A disconnected client must not crash the Master.

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

* [ ] Generate unique request ID
* [ ] Register pending request
* [ ] Assign worker
* [ ] Find request by ID
* [ ] Route response to client
* [ ] Remove completed request

## Definition of Done

Responses always return to the correct client.

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

* [ ] Connect to Unix Socket
* [ ] Encode request
* [ ] Send request
* [ ] Wait for response
* [ ] Decode response
* [ ] Handle connection errors
* [ ] Handle timeouts

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

* [ ] Configure maximum queue size
* [ ] Reject requests when full
* [ ] Track rejected requests
* [ ] Add queue metrics

## Definition of Done

The Master survives overload without uncontrolled memory growth.

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

* [ ] Add request deadline
* [ ] Detect expired requests
* [ ] Return timeout response
* [ ] Remove pending request
* [ ] Track timeout metrics

## Future Improvement

Implement:

```text
Timer Heap
```

instead of scanning all requests.

## Definition of Done

Clients receive a timeout response when work takes too long.

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

* [ ] Handle SIGCHLD
* [ ] Detect dead workers
* [ ] Remove worker
* [ ] Fail active request
* [ ] Start replacement worker

## Definition of Done

The Worker Pool automatically recovers after a Worker crash.

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

* [ ] Handle SIGTERM
* [ ] Stop accepting new connections
* [ ] Drain requests
* [ ] Shutdown workers
* [ ] Wait for children
* [ ] Remove socket file

## Definition of Done

The system shuts down without immediately killing active work.

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
