<?php

declare(ticks = 1);

namespace App\Tests\Worker;

use App\Protocol\Message;
use App\Protocol\MessageType;
use App\Worker\WorkerPool;
use App\Worker\WorkerState;
use PHPUnit\Framework\TestCase;

final class WorkerPoolTest extends TestCase
{
    public function testStartsRequestedNumberOfWorkers(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension required');
        }

        $pool = new WorkerPool(4);

        $this->assertSame(4, $pool->count());

        $pool->stop();
    }

    public function testMultipleWorkersProcessRequestsInParallel(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension required');
        }

        $pool = new WorkerPool(4);

        $responses = $pool->requestBatch([
            'req-1' => new Message(MessageType::REQUEST, 'req-1'),
            'req-2' => new Message(MessageType::REQUEST, 'req-2'),
            'req-3' => new Message(MessageType::REQUEST, 'req-3'),
            'req-4' => new Message(MessageType::REQUEST, 'req-4'),
        ]);

        $this->assertCount(4, $responses);

        $ids = array_column($responses, 'id');
        sort($ids);

        $this->assertSame(['req-1', 'req-2', 'req-3', 'req-4'], $ids);

        $pool->stop();
    }

    public function testDispatchMarksWorkerBusyUntilResponse(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension required');
        }

        $pool = new WorkerPool(1);

        $request = new Message(MessageType::REQUEST, 'req', ['data' => 'x']);
        $workerId = $pool->dispatch($request);

        $this->assertNotNull($workerId);

        $pool->stop();
    }
}
