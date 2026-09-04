# Benchmarks

Numbers from `bin/bench.php` against a real Master, measured inside the
project's own Docker image (PHP 8.5, 12 cores available to the container).
Every figure below is reproducible with the commands shown next to it.

These are not "how fast is PHP" numbers. They exist to answer four questions
the design makes claims about:

1. Does adding workers actually parallelise real work?
2. What is the ceiling when the work is trivial?
3. Does pipelining help, and when?
4. Does backpressure do what it promises under overload?

---

## The tool

`wrk` and `k6` don't apply: this isn't HTTP, it's a length-prefixed protocol
over a Unix domain socket. The driver is the project's own SDK, so the
numbers include the client-side cost a real caller actually pays.

Concurrency comes from forked client processes, not threads - which is also
what a real deployment looks like (N PHP-FPM processes all talking to one
pool).

```bash
php bin/bench.php --clients=8 --requests=500          # against a running server
php bin/bench.php --clients=4 --requests=200 --pipeline=16
```

---

## 1. Workers vs. real work

A handler doing ~2 ms of actual CPU (20 000 iterations of `sqrt`), 8 client
processes, 200 requests each.

| Workers | Throughput  | Speedup | p50      | p95      | p99      |
|--------:|------------:|--------:|---------:|---------:|---------:|
|       1 |    691 rq/s |    1.0× | 11.41 ms | 12.49 ms | 13.13 ms |
|       2 |  1 284 rq/s |    1.9× |  6.04 ms |  7.19 ms |  8.25 ms |
|       4 |  2 531 rq/s |    3.7× |  2.96 ms |  3.56 ms |  4.40 ms |
|       8 |  4 206 rq/s |    6.1× |  1.73 ms |  2.24 ms |  3.00 ms |

Near-linear, and latency halves each time the pool doubles - which is the
whole point of the pool. The gap from 6.1× to a theoretical 8× at eight
workers is the Master's single-threaded routing plus the client processes
competing for the same 12 cores.

---

## 2. The ceiling when the work is trivial

The same shape, but the handler just adds two numbers - so the measurement
is almost entirely framing, socket I/O and the Master's event loop.

| Workers | Throughput   | p50     | p95     | p99     |
|--------:|-------------:|--------:|--------:|--------:|
|       1 | 11 330 rq/s  | 0.66 ms | 0.71 ms | 0.79 ms |
|       2 | 19 180 rq/s  | 0.35 ms | 0.46 ms | 0.57 ms |
|       4 | 19 658 rq/s  | 0.32 ms | 0.49 ms | 0.80 ms |
|       8 | 21 627 rq/s  | 0.30 ms | 0.42 ms | 0.53 ms |

Doubling from one worker to two nearly doubles throughput; after that it
flattens. Nothing is wrong - the workers are no longer the bottleneck. Every
request still has to pass through one single-threaded Master, and at ~20 000
requests per second that is what runs out first.

The practical reading: **more workers only buy throughput while the handler
costs more than the plumbing.** For work this cheap, the pool is the wrong
tool - the answer is to not make the round trip at all.

---

## 3. Pipelining

Four workers, the 2 ms handler, four client processes, varying how many
requests each client keeps in flight (`send()` × N, then `all()`).

| In flight per client | Throughput  | p50     | p99     |
|---------------------:|------------:|--------:|--------:|
|                    1 | 2 298 rq/s  | 1.57 ms | 2.81 ms |
|                    4 | 2 567 rq/s  | 1.45 ms | 3.81 ms |
|                   16 | 2 423 rq/s  | 1.53 ms | 2.54 ms |

Only ~12%, because four clients already keep four workers busy - the pool is
saturated with or without pipelining, and there is nothing left to overlap.

Pipelining pays when a **single** caller has independent work and would
otherwise serialise it. That case, measured separately with a handler
sleeping 0.5 s and three workers:

```text
three send() + all()     0.51s
three call() in sequence 1.50s
```

Same requests, same pool - the difference is purely whether the caller waits
for each answer before sending the next.

---

## 4. Backpressure under overload

One deliberately slow worker (20 ms per request), 8 clients × 32 requests
with 16 in flight each - 128 concurrent requests against a pool that can
serve 50 per second.

| Queue limit | Completed | Rejected | What the client sees                         |
|------------:|----------:|---------:|----------------------------------------------|
|      10 000 |       256 |        0 | every request answered, everyone waits ~5s   |
|          16 |        34 |      222 | 34 answered, 222 × `server_overloaded`, fast |

This is the trade the bounded queue exists to make. Unbounded, the Master
absorbs all 128 and the queue is the only thing standing between a burst and
memory exhaustion; bounded, it serves what it can and tells the rest
immediately, which is the answer a caller can actually act on (retry, shed,
degrade) instead of a timeout five seconds later.

Note on the numbers: the client-side "failed" count reads 224 rather than
222 because `all()` throws on the first rejection in a batch, so the two
requests that did succeed in that batch are counted as failed by the driver.
The server-side counters (34 completed + 222 rejected = 256) are exact.

---

## Reproducing

```bash
make up

# terminal 1
docker compose exec php php bin/server.php

# terminal 2
docker compose exec php php bin/bench.php --clients=8 --requests=500
```

For the worker-count table, run the server with a fixed pool
(`new Master(minWorkers: N, maxWorkers: N, ...)`) so the autoscaler doesn't
move the target mid-measurement, and disable recycling
(`RecyclingPolicy::disabled()`) so no worker is replaced during a run.

---

## One environment note worth the warning

The first version of these benchmarks reported **59 seconds** for an
8-worker run that should have taken under a second - with every individual
request measuring 0.17 ms. The requests were fine; the processes were taking
a minute to *exit*.

The cause was in this repository's own Dockerfile:
`xdebug.start_with_request=yes`, which makes **every** PHP process attempt a
debugger connection to a host that usually isn't listening. One process
barely notices. Eighteen of them (a master, eight workers, eight clients)
turn it into a minute of teardown.

It is now `xdebug.start_with_request=trigger` - debugging is opt-in per run
(`XDEBUG_TRIGGER=1 php bin/server.php`), and nothing pays for it otherwise.
The test suite got faster too, 11.5 s → 7.5 s.

Worth keeping as a lesson rather than just a fix: the first rule of
benchmarking is that a number which disagrees with the rest of your
measurements is usually telling you something about the harness, not the
system.
