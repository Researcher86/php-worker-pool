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
use Closure;

/**
 * Event-driven bridge between the RequestQueue and the WorkerPool.
 *
 * Dispatch is triggered only by events — a request arriving, a worker
 * announcing it is READY, or a worker becoming idle after responding — never
 * by polling. Every worker's socket is registered with the EventLoop for as
 * long as the worker exists, so the master blocks in a single multiplexed
 * select and wakes as soon as any worker has something to say.
 *
 * Registration used to last only while a worker was BUSY, which worked while
 * the only thing a worker ever sent was an answer. The readiness handshake
 * broke that: READY arrives when the worker is NOT busy and nothing has been
 * sent to it, so nobody would have been listening. Watching for the worker's
 * whole life is both simpler and strictly more correct - an idle worker
 * sends nothing, so it never wakes the loop anyway.
 *
 * There used to be a second, synchronous batch API here (run(): submit a
 * list, block until every response arrived) - removed once it was clear only
 * tests ever used it: one execution model to reason about beats two, and
 * everything the batch API did is expressible as dispatch() + ticking the
 * loop until onResponse has delivered what you're waiting for.
 */
final class Dispatcher
{
    /** @var Closure(Message): void */
    private readonly Closure $onResponse;

    /** @var Closure(string): void */
    private readonly Closure $onDispatched;

    /**
     * Sockets currently registered with the loop, by worker pid. Kept here
     * rather than derived from the pool on the fly: once a worker is gone so
     * is its WorkerProcess, and the resource still has to be deregistered -
     * so the handle is remembered while it can still be used.
     *
     * @var array<int, resource>
     */
    private array $watched = [];

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
        $this->onResponse = Closure::fromCallable($onResponse);
        $this->onDispatched = Closure::fromCallable($onDispatched ?? static function (string $id): void {
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
     * Brings the set of watched sockets in line with who is actually in the
     * pool: new workers get a handler, departed ones lose theirs.
     *
     * Pulled from the pool rather than pushed by it, because the pool gains
     * and loses workers in several places (a crash replacement forked inside
     * a SIGCHLD handler, a scale-up, a reload wave) and having each of them
     * notify the Dispatcher would put five call sites where one loop does. It
     * runs on every pump - every dispatch event, and once per Master tick -
     * and costs one comparison per worker.
     */
    private function syncWatches(): void
    {
        $workers = $this->pool->all();

        // Departures FIRST, and the order is load-bearing. EventLoop keys
        // its maps by (int) $resource, and PHP hands a closed stream's id
        // straight back to the next one opened - so a crash replacement
        // forked moments after its predecessor's socket closed can carry the
        // very same id. Registering the newcomer before deregistering the
        // departed would then delete the handler just installed, and that
        // worker's READY would arrive at a socket nobody is listening to:
        // silently one worker short, forever.
        foreach ($this->watched as $pid => $resource) {
            // Gone from the pool, or still in it with its socket already
            // closed - a worker being retired is closed while it waits to be
            // reaped, and there is nothing left to hear from it.
            if (!isset($workers[$pid]) || !is_resource($resource)) {
                $this->loop->removeReadable($resource);
                unset($this->watched[$pid]);
            }
        }

        foreach ($workers as $pid => $worker) {
            if (!isset($this->watched[$pid])) {
                $this->watch($worker);
            }
        }
    }

    /**
     * Registers one worker's socket for its whole life. The handler reads
     * whatever became available and answers each message by kind: a READY
     * puts the worker into rotation, an answer finishes its request. A
     * partial read (message not fully received yet) simply does nothing
     * until the next readable event brings the rest.
     */
    private function watch(WorkerProcess $worker): void
    {
        $resource = $worker->getResource();
        $this->watched[$worker->getPid()] = $resource;

        $this->loop->addReadable($resource, function () use ($worker, $resource): void {
            try {
                foreach ($worker->readAvailable() as $message) {
                    if ($message->type === MessageType::READY) {
                        // Bootstrap finished: STARTING -> IDLE, and the
                        // worker becomes dispatchable for the first time.
                        // Pump right away - this is new capacity, appearing
                        // at a moment nothing else would notice.
                        $this->pool->markReady($worker->getPid());
                        $this->pump();

                        continue;
                    }

                    $finished = $worker->getCurrentRequestId() === $message->id;

                    if ($finished) {
                        $worker->finishRequest();
                    }

                    ($this->onResponse)($message);

                    // PHASES.md Phase 7's second dispatch event: "Worker
                    // Response -> dispatch()". The worker just went idle -
                    // hand it the next queued request immediately.
                    if ($finished) {
                        $this->pump();
                    }
                }
            } catch (ConnectionClosedException | MalformedMessageException) {
                // Either the worker process is gone (ConnectionClosed,
                // PHASES.md Phase 15), or its stream produced bytes that don't
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
                unset($this->watched[$worker->getPid()]);

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
        // First: a worker forked since the last pump (a crash replacement, a
        // scale-up) has to be listened to before its READY arrives, or the
        // announcement lands in a socket nobody watches and it never joins
        // the rotation.
        $this->syncWatches();

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
        }
    }
}
