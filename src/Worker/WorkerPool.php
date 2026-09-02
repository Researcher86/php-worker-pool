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

    /**
     * Dispatches the given requests across available workers and blocks until
     * a response has arrived for every request (matched by id).
     *
     * @param array<string, Message> $requests map of request id => Message
     *
     * @return list<Message>
     *
     * @throws \App\Protocol\MalformedMessageException
     */
    public function requestBatch(array $requests): array
    {
        $queue = $requests;
        $responses = [];

        while ($queue !== [] || $this->busy() > 0) {
            while ($queue !== [] && ($workerId = $this->getAvailable()) !== null) {
                $id = array_key_first($queue);
                $this->write($workerId, $queue[$id]);
                unset($queue[$id]);
            }

            foreach ($this->collectResponses() as $message) {
                $responses[] = $message;
            }
        }

        return $responses;
    }

    private function busy(): int
    {
        return count(array_filter($this->workers, fn ($w) => $w->getState() === WorkerState::BUSY));
    }

    /**
     * Reads a pending response from each busy worker (non-blocking) and returns
     * all complete responses collected. Marks finished workers IDLE.
     *
     * @return list<Message>
     *
     * @throws \App\Protocol\MalformedMessageException
     */
    private function collectResponses(): array
    {
        $messages = [];

        foreach ($this->workers as $worker) {
            if ($worker->getState() !== WorkerState::BUSY) {
                continue;
            }

            foreach ($worker->readAvailable() as $message) {
                if ($worker->getCurrentRequestId() === $message->id) {
                    $worker->finishRequest();
                }

                $messages[] = $message;
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
