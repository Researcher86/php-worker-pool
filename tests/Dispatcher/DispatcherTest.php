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

    /**
     * Backpressure (PLAN.md Phase 13): once the queue is at its configured
     * limit, dispatch() rejects instead of growing it further. Master.php
     * uses the false return to send the client a server_overloaded error
     * instead of leaving it waiting for a response that will never come.
     */
    public function testDispatchRejectsWhenQueueIsFull(): void
    {
        $launcher = new FakeWorkerLauncher();
        $pool = new WorkerPool(1, $launcher);
        $queue = new RequestQueue(1);
        $dispatcher = new Dispatcher($queue, $pool);

        // Goes straight to the only worker - the queue itself stays empty.
        $this->assertTrue($dispatcher->dispatch(new Message(MessageType::REQUEST, 'req-1')));

        // The worker is now busy (never responds), so this one fills the
        // queue's single slot instead.
        $this->assertTrue($dispatcher->dispatch(new Message(MessageType::REQUEST, 'req-2')));

        // Nowhere left for a third request to go.
        $this->assertFalse($dispatcher->dispatch(new Message(MessageType::REQUEST, 'req-3')));

        $this->assertSame(1, $queue->rejectedCount());

        $pool->stop();
    }

    /**
     * Regression test: run() used to check isFull() once before the enqueue
     * loop, so a batch bigger than the remaining capacity still got
     * enqueued in full — defeating the whole point of the bounded queue
     * (Phase 13), which is to never grow past maxSize.
     */
    public function testRunRejectsABatchLargerThanQueueCapacity(): void
    {
        $launcher = new FakeWorkerLauncher();
        $pool = new WorkerPool(1, $launcher); // never responds - nothing gets dequeued
        $queue = new RequestQueue(2);
        $dispatcher = new Dispatcher($queue, $pool);

        try {
            $this->expectException(\RuntimeException::class);

            $dispatcher->run([
                new Message(MessageType::REQUEST, 'req-1'),
                new Message(MessageType::REQUEST, 'req-2'),
                new Message(MessageType::REQUEST, 'req-3'),
            ]);
        } finally {
            // Old behavior would have enqueued all 3 (1 dispatched to the
            // idle worker, 2 left queued) despite maxSize being 2. Rejected
            // atomically instead — nothing from this batch was queued or
            // dispatched at all.
            $this->assertSame(0, $queue->size());
            $this->assertNotNull($pool->getAvailable());

            $pool->stop();
        }
    }

    /**
     * PLAN.md Phase 15's "fail active request": in the event-driven API
     * there's no stall check to fall back on like run() has, so a worker
     * dying mid-request must actively synthesize a response instead of
     * leaving the request pending forever (until Phase 14's timeout, which
     * is much slower than necessary when the cause is already known).
     */
    public function testDispatchReportsWorkerCrashViaOnResponse(): void
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

        $dispatcher->dispatch(new Message(MessageType::REQUEST, 'req-1'));
        $launcher->workerEnds()[0]->close();
        $loop->tick();

        $this->assertCount(1, $responses);
        $this->assertSame(MessageType::ERROR, $responses[0]->type);
        $this->assertSame('req-1', $responses[0]->id);
        $this->assertSame(['error' => 'worker_crashed'], $responses[0]->payload);

        $pool->stop();
    }
}
