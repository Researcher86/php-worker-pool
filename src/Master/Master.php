<?php

declare(strict_types=1);

namespace App\Master;

use App\Protocol\Message;
use App\Protocol\MessageType;
use App\Worker\WorkerPool;

final readonly class Master
{
    public function run(): void
    {
        $pool = new WorkerPool(4);

        $responses = $pool->requestBatch([
            'ping-1' => new Message(MessageType::REQUEST, 'ping-1', ['data' => 'Data 1']),
            'ping-2' => new Message(MessageType::REQUEST, 'ping-2', ['data' => 'Data 2']),
            'ping-3' => new Message(MessageType::REQUEST, 'ping-3', ['data' => 'Data 3']),
            'ping-4' => new Message(MessageType::REQUEST, 'ping-4', ['data' => 'Data 4']),
        ]);

        foreach ($responses as $message) {
            echo json_encode($message) . "\n";
        }

        $pool->stop();
    }
}