<?php

declare(strict_types=1);

namespace App\Master;

use App\Dispatcher\Dispatcher;
use App\Protocol\Message;
use App\Protocol\MessageType;
use App\Queue\RequestQueue;
use App\Worker\WorkerPool;

final readonly class Master
{
    public function run(): void
    {
        $pool = new WorkerPool(4);
        $dispatcher = new Dispatcher(new RequestQueue(), $pool);

        $requests = [];
        for ($i = 1; $i <= 8; $i++) {
            $requests[] = new Message(MessageType::REQUEST, 'ping-' . $i, ['data' => 'Data ' . $i]);
        }

        $responses = $dispatcher->run($requests);

        foreach ($responses as $message) {
            echo json_encode($message) . "\n";
        }

        $pool->stop();
    }
}