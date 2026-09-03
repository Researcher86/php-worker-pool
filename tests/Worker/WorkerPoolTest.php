<?php

declare(ticks = 1);

namespace App\Tests\Worker;

use App\Dispatcher\Dispatcher;
use App\Protocol\Message;
use App\Protocol\MessageType;
use App\Queue\RequestQueue;
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
        $dispatcher = new Dispatcher(new RequestQueue(), $pool);

        $responses = $dispatcher->run([
            new Message(MessageType::REQUEST, 'req-1'),
            new Message(MessageType::REQUEST, 'req-2'),
            new Message(MessageType::REQUEST, 'req-3'),
            new Message(MessageType::REQUEST, 'req-4'),
        ]);

        $this->assertCount(4, $responses);

        $ids = array_column($responses, 'id');
        sort($ids);

        $this->assertSame(['req-1', 'req-2', 'req-3', 'req-4'], $ids);

        $pool->stop();
    }

    public function testGetAvailableReturnsIdleWorkerThenChangesWhenBusy(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension required');
        }

        $pool = new WorkerPool(1);
        $workerId = $pool->getAvailable();

        $this->assertNotNull($workerId);

        $dispatcher = new Dispatcher(new RequestQueue(), $pool);
        $responses = $dispatcher->run([
            new Message(MessageType::REQUEST, 'req', ['data' => 'x']),
        ]);

        $this->assertCount(1, $responses);
        $this->assertSame($workerId, $pool->getAvailable());

        $pool->stop();
    }

    /**
     * PLAN.md Phase 15: a worker can crash outright (not just drop its
     * connection while busy - see DispatcherTest for that case).
     * reapDeadWorkers() is what a SIGCHLD handler calls; it isn't tied to a
     * signal actually firing, so this drives it directly.
     */
    public function testReapDeadWorkersRemovesAndReplacesACrashedWorker(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl and posix extensions required');
        }

        $pool = new WorkerPool(2);
        $deadWorkerId = $pool->getAvailable();
        $this->assertNotNull($deadWorkerId);

        posix_kill($deadWorkerId, SIGKILL);

        // Give the kernel a moment to actually finish the exit so waitpid()
        // has something to reap; reapDeadWorkers() itself never blocks.
        usleep(100_000);

        $crashes = $pool->reapDeadWorkers();

        $this->assertCount(1, $crashes);
        $this->assertSame($deadWorkerId, $crashes[0]->worker->getPid());
        $this->assertNull($crashes[0]->lostRequestId); // it was idle, not mid-request
        $this->assertSame(WorkerState::DEAD, $crashes[0]->worker->getState());

        // Pool stays at its configured size - the crashed one was replaced.
        $this->assertSame(2, $pool->count());

        $pool->stop();
    }
}
