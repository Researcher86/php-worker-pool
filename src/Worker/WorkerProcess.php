<?php

declare(strict_types=1);

namespace App\Worker;

use App\IPC\Socket;

final class WorkerProcess
{
    public function __construct(
        private readonly Socket $socket,
    ) {
    }

    public function run(): void
    {
        $runner = new WorkerRunner($this->socket);

        $runner->run();
    }
}
