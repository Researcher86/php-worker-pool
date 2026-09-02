<?php

declare(strict_types=1);

namespace App\Dispatcher;

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
 * becoming idle after responding — never by polling. The master blocks in a
 * single multiplexed stream_select() across all busy worker sockets and wakes
 * up when any of them has produced a response.
 */
final class Dispatcher
{
    public function __construct(
        private readonly RequestQueue $queue,
        private readonly WorkerPool $pool,
    ) {
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
        }

        return $responses;
    }

    /**
     * Worker-response event: block until at least one busy worker has produced
     * a response, collect it, then dispatch any queued requests to the now-idle
     * workers. Returns after no work remains pending or a response was handled.
     *
     * @return list<Message> responses collected from workers that became readable
     *
     * @throws MalformedMessageException
     */
    public function waitForActivity(): array
    {
        $ready = $this->awaitReadable();

        $responses = [];
        foreach ($ready as $worker) {
            try {
                foreach ($worker->readAvailable() as $message) {
                    if ($worker->getCurrentRequestId() === $message->id) {
                        $worker->finishRequest();
                    }

                    $responses[] = $message;
                }
            } catch (ConnectionClosedException) {
                $worker->markDead();
            }
        }

        $this->pump();

        return $responses;
    }

    /**
     * Blocks in a single multiplexed stream_select() over every busy worker's
     * socket and returns the workers whose socket became readable.
     *
     * @return list<WorkerProcess>
     */
    private function awaitReadable(): array
    {
        $read = [];
        $byResource = [];

        foreach ($this->pool->getBusy() as $worker) {
            $resource = $worker->getResource();
            $read[] = $resource;
            $byResource[(int) $resource] = $worker;
        }

        if ($read === []) {
            return [];
        }

        $write = [];
        $except = [];

        stream_select($read, $write, $except, null);

        $ready = [];
        foreach ($read as $resource) {
            $ready[] = $byResource[(int) $resource];
        }

        return $ready;
    }

    /**
     * Sends queued requests to every idle worker until none remain.
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

            $this->pool->write($workerId, $this->queue->dequeue());
        }
    }
}