<?php

declare(ticks = 1);

namespace App\Worker;

use App\IPC\ConnectionClosedException;
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

    /**
     * Returns the id of any worker that can currently accept a request, or
     * null if all workers are busy, stopping, or dead.
     */
    public function getAvailable(): ?int
    {
        foreach ($this->workers as $id => $worker) {
            if ($worker->isAvailable()) {
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
        $pending = array_fill_keys(array_keys($requests), true);
        $responses = [];

        while ($queue !== [] || $this->busy() > 0) {
            while ($queue !== [] && ($workerId = $this->getAvailable()) !== null) {
                $id = array_key_first($queue);
                $this->write($workerId, $queue[$id]);
                unset($queue[$id]);
            }

            foreach ($this->collectResponses() as $message) {
                if (isset($pending[$message->id])) {
                    unset($pending[$message->id]);
                    $responses[] = $message;
                }
            }
        }

        return $responses;
    }

    private function busy(): int
    {
        return count(array_filter($this->workers, $this->isBusy(...)));
    }

    private function isBusy(WorkerProcess $worker): bool
    {
        return $worker->getState() === WorkerState::BUSY;
    }

    /**
     * Reads a pending response from each busy worker (non-blocking) and returns
     * all complete responses collected. Marks finished workers IDLE, and
     * workers whose connection dropped mid-request DEAD.
     *
     * @return list<Message>
     *
     * @throws \App\Protocol\MalformedMessageException
     */
    private function collectResponses(): array
    {
        $messages = [];

        foreach ($this->workers as $worker) {
            if (!$this->isBusy($worker)) {
                continue;
            }

            try {
                foreach ($worker->readAvailable() as $message) {
                    if ($worker->getCurrentRequestId() === $message->id) {
                        $worker->finishRequest();
                    }

                    $messages[] = $message;
                }
            } catch (ConnectionClosedException) {
                $worker->markDead();
            }
        }

        return $messages;
    }

    public function stop(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->getState() !== WorkerState::DEAD) {
                $worker->stop();
                $worker->write(new Message(MessageType::SHUTDOWN, 'shutdown-' . $worker->getPid()));
            }

            $worker->close();
        }

        while (pcntl_waitpid(-1, $status) !== -1) {
            // reap all children
        }

        foreach ($this->workers as $worker) {
            if ($worker->getState() !== WorkerState::DEAD) {
                $worker->markDead();
            }
        }
    }
}
