<?php

declare(ticks = 1);

namespace App\Worker;

use App\IPC\Socket;
use App\IPC\SocketPair;

class WorkerPool
{
    /** @var array<int, Socket> */
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

            $this->workers[$pid] = $socketPair->getMasterSocket();
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

    public function write(int $workerId, string $data): void
    {
        $this->workers[$workerId]->write($data);
    }

    public function read(int $workerId): string|false
    {
        return $this->workers[$workerId]->read();
    }

    public function stop(): void
    {
        foreach ($this->workers as $worker) {
            $worker->write("STOP\n");
            $worker->close();
        }

        while (pcntl_waitpid(-1, $status) !== -1) {
            // reap all children
        }
    }
}
