<?php

declare(strict_types=1);

namespace App\Dispatcher;

use App\EventLoop\EventLoop;
use App\IPC\ConnectionClosedException;
use App\Protocol\MalformedMessageException;
use App\Protocol\Message;
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
 */
final class Dispatcher
{
    private readonly EventLoop $loop;

    /** @var \Closure(Message): void */
    private readonly \Closure $onResponse;

    /** @var list<Message> */
    private array $collected = [];

    /**
     * @param callable(Message): void|null $onResponse invoked with every
     *        response as soon as it's read from a worker - the event-driven
     *        counterpart to draining waitForActivity()/run()'s return value.
     *        Defaults to a no-op for callers that only use the batch API.
     */
    public function __construct(
        private readonly RequestQueue $queue,
        private readonly WorkerPool $pool,
        ?EventLoop $loop = null,
        ?callable $onResponse = null,
    ) {
        $this->loop = $loop ?? new EventLoop();
        $this->onResponse = \Closure::fromCallable($onResponse ?? static function (Message $message): void {
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
     * Dispatches every request across the pool and blocks until a response has
     * arrived for each one. Requests beyond the number of workers wait in the
     * queue and are dispatched automatically as workers become idle.
     *
     * @param list<Message> $requests
     *
     * @return list<Message> responses, one for each submitted request
     *
     * @throws MalformedMessageException
     * @throws UnresolvedRequestsException if a worker dies while holding a
     *         request and no other worker can ever pick it up
     */
    public function run(array $requests): array
    {
        $pending = [];
        foreach ($requests as $request) {
            $this->queue->enqueue($request);
            $pending[$request->id] = true;
        }
        $this->pump();

        $responses = [];
        while ($pending !== []) {
            foreach ($this->waitForActivity() as $message) {
                // Only keep responses for requests this call submitted —
                // guards against a stray/duplicate message with an id we
                // aren't tracking ever ending up in the returned list.
                if (isset($pending[$message->id])) {
                    unset($pending[$message->id]);
                    $responses[] = $message;
                }
            }

            // A worker can die while holding a request (see watch() below);
            // when that happens its response will never arrive. If nothing
            // is queued and no worker socket is being watched, there is
            // nothing left that could ever resolve the remaining pending
            // ids — waiting again would block forever, so bail instead.
            if ($pending !== [] && $this->queue->isEmpty() && !$this->loop->hasReadable()) {
                throw new UnresolvedRequestsException(array_keys($pending));
            }
        }

        return $responses;
    }

    /**
     * Worker-response event: block until at least one busy worker's socket
     * becomes readable, collect whatever responses that produced, then
     * dispatch any queued requests to the now-idle workers.
     *
     * @return list<Message> responses collected from workers that became readable
     *
     * @throws MalformedMessageException
     */
    public function waitForActivity(): array
    {
        $this->collected = [];

        $this->loop->tick();
        $this->pump();

        return $this->collected;
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
                    if ($worker->getCurrentRequestId() === $message->id) {
                        $worker->finishRequest();
                        $this->loop->removeReadable($resource);
                    }

                    $this->collected[] = $message;
                    ($this->onResponse)($message);
                }
            } catch (ConnectionClosedException) {
                // The worker process is gone. Stop watching its socket —
                // nothing will ever become readable on it again — and let
                // run()'s stall check notice its request can't be answered.
                $worker->markDead();
                $this->loop->removeReadable($resource);
            }
        });
    }

    /**
     * Sends queued requests to every idle worker until none remain, watching
     * each one's socket for its response.
     *
     * @throws MalformedMessageException
     */
    private function pump(): void
    {
        while (!$this->queue->isEmpty()) {
            $workerId = $this->pool->getAvailable();

            if ($workerId === null) {
                return; // all workers busy; wait for a worker response
            }

            $this->watch($this->pool->write($workerId, $this->queue->dequeue()));
        }
    }
}
