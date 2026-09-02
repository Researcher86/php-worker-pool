<?php

declare(strict_types=1);

namespace App\Master;

use App\Worker\WorkerPool;

final readonly class Master
{
    public function __construct()
    {
    }

    public function run(): void
    {
        $pool = new WorkerPool(1);

        $workerId = $pool->get();

        $pool->write($workerId, "Master -> PING\n");
        $pool->write($workerId, "Master -> PING\n");
        $pool->write($workerId, "Master -> PING\n");

        echo $pool->read($workerId);
        echo $pool->read($workerId);
        echo $pool->read($workerId);

        $pool->stop();
    }
}
