<?php

declare(strict_types=1);

namespace App\Tests\Dispatcher;

use App\Dispatcher\Dispatcher;
use App\EventLoop\EventLoop;
use App\Protocol\Message;
use App\Protocol\MessageType;
use App\Queue\RequestQueue;
use App\Tests\Worker\FakeWorkerLauncher;
use App\Worker\WorkerPool;
use PHPUnit\Framework\TestCase;

final class DispatcherTest extends TestCase
{
    /**
     * Ticks the loop until $responses holds $expected messages - bounded, so
     * a response that never arrives fails an assertion instead of hanging
     * the test.
     *
     * @param list<Message> $responses
     */
    private function tickUntil(EventLoop $loop, array &$responses, int $expected): void
    {
        for ($i = 0; $i < 200 && count($responses) < $expected; $i++) {
            $loop->tick(0.1);
        }
    }

    /**
     * More requests than workers: the surplus waits in the queue and is
     * dispatched automatically as workers answer and become idle again -
     * exercised end to end against real forked workers.
     */
    public function testProcessesMoreRequestsThanWorkersThroughTheQueue(): void
    {
        $pool = new WorkerPool(2);
        $loop = new EventLoop();
        $responses = [];

        $dispatcher = new Dispatcher(new RequestQueue(), $pool, $loop, function (Message $message) use (&$responses): void {
            $responses[] = $message;
        });

        for ($i = 1; $i <= 6; $i++) {
            $this->assertTrue($dispatcher->dispatch(new Message(MessageType::REQUEST, 'req-' . $i)));
        }

        $this->tickUntil($loop, $responses, 6);

        $ids = array_column($responses, 'id');
        sort($ids);

        $this->assertSame(['req-1', 'req-2', 'req-3', 'req-4', 'req-5', 'req-6'], $ids);

        // Everything answered: the queue drained and the workers went idle again.
        $this->assertNotNull($pool->getAvailable());

        $pool->stop();
    }

    /**
     * PLAN.md Phase 15 against a real forked worker: SIGKILL it while it
     * holds a request - the event-driven path must synthesize worker_crashed
     * for that request (the fake-launcher variant further down covers the
     * same logic deterministically, with no processes or OS timing at all).
     */
    public function testWorkerDyingMidRequestReportsWorkerCrashed(): void
    {
        $pool = new WorkerPool(1);
        $loop = new EventLoop();
        $responses = [];

        $dispatcher = new Dispatcher(new RequestQueue(), $pool, $loop, function (Message $message) use (&$responses): void {
            $responses[] = $message;
        });

        // WorkerPool keys workers by pid, so getAvailable() doubles as "give
        // me a worker id". Killed BEFORE dispatching, so it can never answer -
        // whether the write reaches it or not, its socket ends in EOF and the
        // only possible outcome is the synthesized crash error.
        $workerId = $pool->getAvailable();
        $this->assertNotNull($workerId);
        posix_kill($workerId, SIGKILL);

        $dispatcher->dispatch(new Message(MessageType::REQUEST, 'req-1'));
        $this->tickUntil($loop, $responses, 1);

        $this->assertCount(1, $responses);
        $this->assertSame(MessageType::ERROR, $responses[0]->type);
        $this->assertSame('req-1', $responses[0]->id);
        $this->assertSame(['error' => 'worker_crashed'], $responses[0]->payload);

        $pool->stop();
    }

    /**
     * dispatch() enqueues a single request without blocking, and onResponse
     * is invoked as soon as its answer is read - the mechanism Master.php
     * uses to route a response back to whichever client sent the matching
     * request (Phase 11). Exercised via WorkerLauncher's fake: a real socket
     * pair with no process behind it, the test playing "the worker".
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
        $dispatcher = new Dispatcher($queue, $pool, new EventLoop(), static function (): void {
        });

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
     * PLAN.md Phase 15's "fail active request", deterministically: no
     * pcntl_fork, no posix_kill, no OS-timing race - just closing the fake
     * worker's end of the pair. A worker dying mid-request must actively
     * synthesize a response instead of leaving the request pending forever
     * (until Phase 14's timeout, which is much slower than necessary when
     * the cause is already known).
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

    /**
     * A worker whose stream produces unparseable bytes (a framing desync -
     * e.g. the fallout of a half-written frame) used to let
     * MalformedMessageException escape the read handler, through
     * EventLoop::tick(), straight out of Master's main loop: one bad byte
     * stream from one worker took the whole Master down. It's treated
     * exactly like a crash now - the in-flight request fails with
     * worker_crashed and the worker is dropped, nothing propagates.
     */
    public function testMalformedBytesFromAWorkerAreTreatedAsACrashNotAMasterCrash(): void
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

        // A frame claiming a 5-byte payload that is actually invalid JSON -
        // written raw, since Socket::write() itself can only produce valid
        // frames.
        fwrite($launcher->workerEnds()[0]->getResource(), pack('N', 5) . 'not{}');
        $loop->tick();

        $this->assertCount(1, $responses);
        $this->assertSame(MessageType::ERROR, $responses[0]->type);
        $this->assertSame('req-1', $responses[0]->id);
        $this->assertSame(['error' => 'worker_crashed'], $responses[0]->payload);

        $this->assertFalse($loop->hasReadable(), 'the desynced worker must be deregistered from the loop');
        $this->assertNull($pool->getAvailable(), 'the desynced worker must not be dispatched to again');

        $pool->stop();
    }
}
