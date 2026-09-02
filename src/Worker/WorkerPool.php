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

    /** @return list<WorkerProcess> */
    public function getBusy(): array
    {
        return array_values(array_filter($this->workers, $this->isBusy(...)));
    }

    public function write(int $workerId, Message $message): void
    {
        $this->workers[$workerId]->write($message);
        $this->workers[$workerId]->beginRequest($message->id);
    }

    private function isBusy(WorkerProcess $worker): bool
    {
        return $worker->getState() === WorkerState::BUSY;
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
