<?php

declare(strict_types=1);

namespace App\Tests\Dispatcher;

use App\Dispatcher\Dispatcher;
use App\Protocol\Message;
use App\Protocol\MessageType;
use App\Queue\RequestQueue;
use App\Worker\WorkerPool;
use PHPUnit\Framework\TestCase;

final class DispatcherTest extends TestCase
{
    public function testProcessesMoreRequestsThanWorkersThroughTheQueue(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension required');
        }

        $pool = new WorkerPool(2);
        $dispatcher = new Dispatcher(new RequestQueue(), $pool);

        $requests = [];
        for ($i = 1; $i <= 6; $i++) {
            $requests[] = new Message(MessageType::REQUEST, 'req-' . $i);
        }

        $responses = $dispatcher->run($requests);

        $ids = array_column($responses, 'id');
        sort($ids);

        $this->assertCount(6, $responses);
        $this->assertSame(
            ['req-1', 'req-2', 'req-3', 'req-4', 'req-5', 'req-6'],
            $ids
        );

        $pool->stop();
    }

    public function testAllWorkersAreAvailableAfterBatchCompletes(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension required');
        }

        $pool = new WorkerPool(2);
        $dispatcher = new Dispatcher(new RequestQueue(), $pool);

        $dispatcher->run([
            new Message(MessageType::REQUEST, 'req-1'),
            new Message(MessageType::REQUEST, 'req-2'),
        ]);

        $this->assertNotNull($pool->getAvailable());

        $pool->stop();
    }
}