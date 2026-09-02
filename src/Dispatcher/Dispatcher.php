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

    /** @var list<Message> */
    private array $collected = [];

    public function __construct(
        private readonly RequestQueue $queue,
        private readonly WorkerPool $pool,
        ?EventLoop $loop = null,
    ) {
        $this->loop = $loop ?? new EventLoop();
    }

    /**
     * Dispatch event: queue a request and push as much work as possible onto
     * the currently idle workers.
     */
    public function dispatch(Message $request): void
    {
        $this->queue->enqueue($request);
        $this->pump();
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
                if (isset($pending[$message->id])) {
                    unset($pending[$message->id]);
                    $responses[] = $message;
                }
            }

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
     * worker is no longer busy (finished or dead) — a partial read leaves it
     * registered so the next readable event picks up the rest.
     */
    private function watch(WorkerProcess $worker): void
    {
        $this->loop->addReadable($worker->getResource(), function () use ($worker): void {
            try {
                foreach ($worker->readAvailable() as $message) {
                    if ($worker->getCurrentRequestId() === $message->id) {
                        $worker->finishRequest();
                        $this->loop->removeReadable($worker->getResource());
                    }

                    $this->collected[] = $message;
                }
            } catch (ConnectionClosedException) {
                $worker->markDead();
                $this->loop->removeReadable($worker->getResource());
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
