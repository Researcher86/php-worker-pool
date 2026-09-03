<?php

declare(strict_types=1);

namespace App\Tests\Dispatcher;

use App\Dispatcher\Dispatcher;
use App\Dispatcher\UnresolvedRequestsException;
use App\EventLoop\EventLoop;
use App\Protocol\Message;
use App\Protocol\MessageType;
use App\Queue\RequestQueue;
use App\Tests\Worker\FakeWorkerLauncher;
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

    public function testWorkerDyingMidRequestThrowsInsteadOfHanging(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl and posix extensions required');
        }

        $pool = new WorkerPool(1);

        // WorkerPool keys workers by pid, so getAvailable() doubles as "give me
        // a worker id", true before any request has ever been sent to it.
        $workerId = $pool->getAvailable();
        $this->assertNotNull($workerId);

        posix_kill($workerId, SIGKILL);

        $dispatcher = new Dispatcher(new RequestQueue(), $pool);

        try {
            $this->expectException(UnresolvedRequestsException::class);

            $dispatcher->run([
                new Message(MessageType::REQUEST, 'req-1'),
            ]);
        } finally {
            $pool->stop();
        }
    }

    /**
     * Same dispatch/queue/matching logic as the tests above, but exercised
     * without pcntl_fork at all — WorkerLauncher makes that possible: a fake
     * launcher hands WorkerPool a real socket pair with no process behind it,
     * and the test plays "the worker" by writing directly to its end.
     */
    public function testRespondsWithoutForkingARealWorkerProcess(): void
    {
        $launcher = new FakeWorkerLauncher();
        $pool = new WorkerPool(1, $launcher);
        $dispatcher = new Dispatcher(new RequestQueue(), $pool);

        $launcher->workerEnds()[0]->write(new Message(MessageType::RESPONSE, 'req-1', ['answer' => 42]));

        $responses = $dispatcher->run([
            new Message(MessageType::REQUEST, 'req-1'),
        ]);

        $this->assertCount(1, $responses);
        $this->assertSame('req-1', $responses[0]->id);
        $this->assertSame(['answer' => 42], $responses[0]->payload);

        $pool->stop();
    }

    /**
     * The same scenario as testWorkerDyingMidRequestThrowsInsteadOfHanging
     * above, but deterministic: no pcntl_fork, no posix_kill, no OS-timing
     * race between a clean EOF and a broken-pipe error to worry about —
     * just closing the fake worker's end of the pair.
     */
    public function testWorkerDyingMidRequestThrowsInsteadOfHangingWithoutForkingOrSignals(): void
    {
        $launcher = new FakeWorkerLauncher();
        $pool = new WorkerPool(1, $launcher);
        $dispatcher = new Dispatcher(new RequestQueue(), $pool);

        $launcher->workerEnds()[0]->close();

        try {
            $this->expectException(UnresolvedRequestsException::class);

            $dispatcher->run([
                new Message(MessageType::REQUEST, 'req-1'),
            ]);
        } finally {
            $pool->stop();
        }
    }

    /**
     * The event-driven counterpart to run(): dispatch() enqueues a single
     * request without blocking, and onResponse is invoked as soon as its
     * answer is read - the mechanism Master.php uses to route a response
     * back to whichever client sent the matching request (Phase 11).
     */
    public function testDispatchInvokesOnResponseCallbackAsResponsesArrive(): void
    {
        $launcher = new FakeWorkerLauncher();
        $pool = new WorkerPool(1, $launcher);
        $loop = new EventLoop();
        $responses = [];

        $dispatcher = new Dispatcher(
            new RequestQueue(),
            $pool,
            $loop,
            function (Message $message) use (&$responses): void {
                $responses[] = $message;
            }
        );

        $launcher->workerEnds()[0]->write(new Message(MessageType::RESPONSE, 'req-1', ['answer' => 42]));

        $dispatcher->dispatch(new Message(MessageType::REQUEST, 'req-1'));
        $loop->tick();

        $this->assertCount(1, $responses);
        $this->assertSame('req-1', $responses[0]->id);
        $this->assertSame(['answer' => 42], $responses[0]->payload);

        $pool->stop();
    }
}
