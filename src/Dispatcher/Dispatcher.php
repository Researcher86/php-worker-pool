<?php

declare(strict_types=1);

namespace App\Dispatcher;

use App\EventLoop\EventLoop;
use App\IPC\ConnectionClosedException;
use App\Protocol\MalformedMessageException;
use App\Protocol\Message;
use App\Protocol\MessageType;
use App\Queue\RequestQueue;
use App\Worker\WorkerPool;
use App\Worker\WorkerProcess;

/**
 * Event-driven bridge between the RequestQueue and the WorkerPool.
 *
 * Dispatch is triggered only by events — a request arriving, or a worker
 * becoming idle after responding — never by polling. Each worker's socket is
 * registered with the EventLoop only while it is busy, so the master blocks
 * in a single multiplexed select across every worker currently in flight and
 * wakes up as soon as any of them has produced a response.
 *
 * There used to be a second, synchronous batch API here (run(): submit a
 * list, block until every response arrived) - removed once it was clear only
 * tests ever used it: one execution model to reason about beats two, and
 * everything the batch API did is expressible as dispatch() + ticking the
 * loop until onResponse has delivered what you're waiting for.
 */
final class Dispatcher
{
    /** @var \Closure(Message): void */
    private readonly \Closure $onResponse;

    /** @var \Closure(string): void */
    private readonly \Closure $onDispatched;

    /**
     * @param callable(Message): void $onResponse invoked with every response
     *        as soon as it's read from a worker — either a real reply, or the
     *        worker_crashed error synthesized when a worker died mid-request
     */
    public function __construct(
        private readonly RequestQueue $queue,
        private readonly WorkerPool $pool,
        private readonly EventLoop $loop,
        callable $onResponse,
        // Invoked with a request's id the moment it reaches a worker - the
        // boundary between "waiting for capacity" and "being worked on",
        // which nothing else can observe from outside this class.
        ?callable $onDispatched = null,
    ) {
        $this->onResponse = \Closure::fromCallable($onResponse);
        $this->onDispatched = \Closure::fromCallable($onDispatched ?? static function (string $id): void {
        });
    }

    /**
     * Dispatch event: queue a request and push as much work as possible onto
     * the currently idle workers.
     *
     * @return bool false if the queue is at its configured limit and the
     *         request was rejected instead of queued (see RequestQueue) -
     *         the caller decides what a rejection means for whoever sent it.
     */
    public function dispatch(Message $request): bool
    {
        if ($this->queue->isFull()) {
            $this->queue->recordRejection();

            return false;
        }

        $this->queue->enqueue($request);
        $this->pump();

        return true;
    }

    /**
     * Push queued requests onto whatever workers are free right now - a
     * no-op when the queue is empty or nothing is available. The two dispatch
     * events (a request arriving, a worker answering) already pump
     * internally; this exists for capacity that appears OUTSIDE a dispatch
     * event - a crash replacement being launched, a scale-up, a reload's
     * fresh generation - which the Dispatcher itself never observes. Master
     * calls it once per tick, same as its other per-tick sweeps.
     */
    public function dispatchQueued(): void
    {
        $this->pump();
    }

    /**
     * Registers a worker's socket with the event loop for as long as it is
     * busy. The handler reads whatever became available, finishes the request
     * once its response has arrived, and deregisters the socket once the
     * worker is no longer busy (finished or dead) — a partial read (message
     * not fully received yet) leaves it registered so the next readable
     * event picks up the rest.
     */
    private function watch(WorkerProcess $worker): void
    {
        $resource = $worker->getResource();

        $this->loop->addReadable($resource, function () use ($worker, $resource): void {
            try {
                foreach ($worker->readAvailable() as $message) {
                    $finished = $worker->getCurrentRequestId() === $message->id;

                    if ($finished) {
                        $worker->finishRequest();
                        $this->loop->removeReadable($resource);
                    }

                    ($this->onResponse)($message);

                    // PLAN.md Phase 7's second dispatch event: "Worker
                    // Response -> dispatch()". The worker just went idle -
                    // hand it the next queued request immediately (this may
                    // re-register the very socket deregistered above, now
                    // watching for the new request's response).
                    if ($finished) {
                        $this->pump();
                    }
                }
            } catch (ConnectionClosedException | MalformedMessageException) {
                // Either the worker process is gone (ConnectionClosed,
                // PLAN.md Phase 15), or its stream produced bytes that don't
                // parse (Malformed - a framing desync, which has no recovery).
                // Both make the worker unusable, and letting Malformed
                // propagate would take the whole Master down - nothing above
                // this handler catches it. Treat both as a crash. Capture the
                // id before markDead() clears it — WorkerPool::reapDeadWorkers()
                // may independently detect and report the same crash via
                // SIGCHLD; whichever of the two gets here first is the one
                // that actually has a non-null id to report, the other just
                // sees it already cleared and does nothing extra.
                $requestId = $worker->getCurrentRequestId();
                $worker->markDead();
                $this->loop->removeReadable($resource);

                // For a desynced-but-still-running worker this is what ends
                // it: closing our end gives its next read EOF, it exits, and
                // SIGCHLD reaps and replaces it like any other crash. For a
                // worker that's already gone this just releases our fd early
                // instead of waiting for the handle to be garbage-collected.
                $worker->close();

                // Synthesize a response for the request that will now never
                // be answered: onResponse is already "something happened to
                // this request id" for Master, whether it's a real worker
                // reply or, as here, an error standing in for one.
                if ($requestId !== null) {
                    ($this->onResponse)(new Message(MessageType::ERROR, $requestId, ['error' => 'worker_crashed']));
                }
            }
        });
    }

    /**
     * Sends queued requests to every idle worker until none remain, watching
     * each one's socket for its response.
     */
    private function pump(): void
    {
        while (!$this->queue->isEmpty()) {
            $workerId = $this->pool->getAvailable();

            if ($workerId === null) {
                return; // all workers busy; wait for a worker response
            }

            $request = $this->queue->dequeue();
            $worker = $this->pool->write($workerId, $request);

            if ($worker === null) {
                // The worker vanished between getAvailable() and write()
                // (reaped by an async SIGCHLD in between). Requeue and go
                // around - the next getAvailable() no longer sees it, so
                // this can't loop on the same worker.
                $this->queue->enqueue($request);

                continue;
            }

            ($this->onDispatched)($request->id);
            $this->watch($worker);
        }
    }
}
