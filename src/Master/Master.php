<?php

declare(strict_types=1);

namespace App\Master;

use App\Protocol\Message;
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

        $pool->write($workerId, new Message('ping', 'ping-1'));
        $pool->write($workerId, new Message('ping', 'ping-2'));
        $pool->write($workerId, new Message('ping', 'ping-3'));

        $responses = 0;
        while ($responses < 3) {
            foreach ($pool->read($workerId) as $message) {
                echo $message->type . ' ' . $message->id . "\n";
                $responses++;
            }
        }

        $pool->stop();
    }
}