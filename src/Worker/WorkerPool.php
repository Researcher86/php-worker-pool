<?php

declare(ticks = 1);

namespace App\Worker;

use App\IPC\SocketPair;
use App\Protocol\Message;

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

    public function write(int $workerId, Message $message): void
    {
        $this->workers[$workerId]->write($message);
    }

    /** @return list<Message> */
    public function read(int $workerId): array
    {
        return $this->workers[$workerId]->read();
    }

    public function stop(): void
    {
        foreach ($this->workers as $worker) {
            $worker->write(new Message('shutdown', 'shutdown-' . $worker->getPid()));
            $worker->close();
        }

        while (pcntl_waitpid(-1, $status) !== -1) {
            // reap all children
        }
    }
}
