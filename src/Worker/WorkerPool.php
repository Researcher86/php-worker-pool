<?php

declare(ticks = 1);

namespace App\Worker;

use App\IPC\SocketPair;
use App\Protocol\Message;
use App\Protocol\MessageType;

class WorkerPool
{
    /** @var array<int, WorkerProcess> */
    private array $workers = [];

    public function __construct(int $workerCount)
    {
        for ($i = 0; $i < $workerCount; $i++) {
            $socketPair = new SocketPair();

            $pid = pcntl_fork();
            if ($pid === -1) {
                die('fork failed');
            }

            if ($pid === 0) {
                $socketPair->closeMaster();

                $runner = new WorkerRunner($socketPair->getWorkerSocket());
                $runner->run();

                exit(0);
            }

            $socketPair->closeWorker();

            $this->workers[$pid] = new WorkerProcess($pid, $socketPair->getMasterSocket());
        }
    }

    public function count(): int
    {
        return count($this->workers);
    }

    public function get(): int
    {
        return array_key_last($this->workers);
    }

    /**
     * Returns the id of any worker that is not busy, or null if all workers
     * are currently handling a request.
     */
    public function getAvailable(): ?int
    {
        foreach ($this->workers as $id => $worker) {
            if ($worker->getState() !== WorkerState::BUSY) {
                return $id;
            }
        }

        return null;
    }

    public function write(int $workerId, Message $message): void
    {
        $this->workers[$workerId]->write($message);
        $this->workers[$workerId]->beginRequest($message->id);
    }

    /** @return list<Message> */
    public function read(int $workerId): array
    {
        $messages = $this->workers[$workerId]->read();

        foreach ($messages as $message) {
            if ($this->workers[$workerId]->getCurrentRequestId() === $message->id) {
                $this->workers[$workerId]->finishRequest();
            }
        }

        return $messages;
    }

    /**
     * Dispatches a batch of requests, each to an available worker, and blocks
     * until a response has arrived for every request (matched by id).
     *
     * @param array<string, Message> $requests map of request id => Message
     *
     * @return list<Message>
     *
     * @throws \App\Protocol\MalformedMessageException
     */
    public function requestBatch(array $requests): array
    {
        $pending = array_fill_keys(array_keys($requests), true);

        foreach ($requests as $request) {
            $this->dispatch($request);
        }

        $responses = [];

        while ($pending !== []) {
            foreach ($this->readAll() as $message) {
                if (isset($pending[$message->id])) {
                    unset($pending[$message->id]);
                    $responses[] = $message;
                }
            }
        }

        return $responses;
    }

    /**
     * Sends a single request to an available worker and returns its id. Blocks
     * until a worker becomes free if all of them are busy.
     *
     * @throws \App\Protocol\MalformedMessageException
     */
    public function dispatch(Message $request): int
    {
        while (($workerId = $this->getAvailable()) === null) {
            $this->readAll();
        }

        $this->write($workerId, $request);

        return $workerId;
    }

    /**
     * Reads a pending response from each busy worker using a short non-blocking
     * timeout. Skips workers that are not currently handling a request so the
     * call never blocks on an idle socket.
     *
     * @return list<Message>
     *
     * @throws \App\Protocol\MalformedMessageException
     */
    public function readAll(): array
    {
        $messages = [];

        foreach ($this->workers as $id => $worker) {
            if ($worker->getState() === WorkerState::BUSY) {
                foreach ($worker->readAvailable() as $message) {
                    if ($worker->getCurrentRequestId() === $message->id) {
                        $worker->finishRequest();
                    }
                    $messages[] = $message;
                }
            }
        }

        return $messages;
    }

    public function stop(): void
    {
        foreach ($this->workers as $worker) {
            $worker->write(new Message(MessageType::SHUTDOWN, 'shutdown-' . $worker->getPid()));
            $worker->close();
        }

        while (pcntl_waitpid(-1, $status) !== -1) {
            // reap all children
        }
    }
}
