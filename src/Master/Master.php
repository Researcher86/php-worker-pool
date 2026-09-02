<?php

declare(strict_types=1);

namespace App\Master;

use App\Protocol\Message;
use App\Protocol\MessageType;
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

        $responses = $pool->requestBatch($workerId, [
            'ping-1' => new Message(MessageType::REQUEST, 'ping-1', ['data' => 'Data 1']),
            'ping-2' => new Message(MessageType::REQUEST, 'ping-2', ['data' => 'Data 2']),
            'ping-3' => new Message(MessageType::REQUEST, 'ping-3', ['data' => 'Data 3']),
        ]);

        foreach ($responses as $message) {
            echo json_encode($message) . "\n";
        }

        $pool->stop();
    }
}