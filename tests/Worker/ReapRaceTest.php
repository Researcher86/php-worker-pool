<?php

declare(strict_types=1);

namespace App\Tests\Worker;

use App\Dispatcher\Dispatcher;
use App\EventLoop\EventLoop;
use App\Protocol\Message;
use App\Protocol\MessageType;
use App\Queue\RequestQueue;
use App\Worker\Autoscaler;
use App\Worker\WorkerPool;
use App\Worker\WorkerState;
use PHPUnit\Framework\TestCase;

/**
 * The window that async SIGCHLD opens, exercised deterministically.
 *
 * With pcntl_async_signals(true) the reaper can run between ANY two
 * statements, and the sharpest place for that is between "which worker is
 * free?" and "send it this request". These tests don't deliver a real signal
 * - they call reapDeadWorkers() at exactly the point the handler could have,
 * which is the same thing minus the timing luck.
 */
final class ReapRaceTest extends TestCase
{
    /**
     * getAvailable() said yes, the reaper removed the worker, and only then
     * did the dispatch happen. write() must refuse rather than resurrect a
     * pid it no longer has.
     */
    public function testWriteRefusesAWorkerReapedSinceGetAvailable(): void
    {
        $pool = new WorkerPool(2);

        $chosen = $pool->getAvailable();
        $this->assertNotNull($chosen);

        // ...the exact window: the worker dies and SIGCHLD lands here.
        posix_kill($chosen, SIGKILL);
        usleep(100_000);
        $pool->reapDeadWorkers();

        $this->assertNull(
            $pool->write($chosen, new Message(MessageType::REQUEST, 'req-1')),
            'dispatching to a reaped worker must fail cleanly, not throw or half-send'
        );

        $pool->stop();
    }

    /**
     * And the request must survive that refusal: Dispatcher requeues it and
     * it lands on another worker instead of being silently dropped.
     */
    public function testARequestSurvivesTheWorkerItWasHeadedForDying(): void
    {
        $launcher = new FakeWorkerLauncher();
        $pool = new WorkerPool(2, $launcher);
        $loop = new EventLoop();
        $responses = [];

        $dispatcher = new Dispatcher(new RequestQueue(), $pool, $loop, function (Message $m) use (&$responses): void {
            $responses[] = $m;
        });

        // Close the worker the dispatcher will reach for first, so its write
        // fails the way a reaped worker's would.
        $doomed = $pool->getAvailable();
        $launcher->workerEnds()[0]->close();

        $dispatcher->dispatch(new Message(MessageType::REQUEST, 'req-1'));

        // Whatever happened to the first worker, the request was answered by
        // someone - the queue is empty and a worker owns it.
        $secondEnd = $launcher->workerEnds()[1];
        $secondEnd->write(new Message(MessageType::RESPONSE, 'req-1', ['ok' => true]));

        for ($i = 0; $i < 50 && $responses === []; $i++) {
            $loop->tick(0.05);
        }

        $this->assertNotSame([], $responses, 'the request must not vanish with the worker');
        $this->assertSame('req-1', $responses[0]->id);

        $pool->stop();
    }

    /**
     * The review's §9: a worker marked DEAD by the Dispatcher but not yet
     * reaped is still present in $workers. Scaling decisions must not count
     * it as capacity (it can never take a request) nor as headroom to fill
     * past the cap.
     */
    public function testScalingIgnoresAWorkerThatIsDeadButNotYetReaped(): void
    {
        $launcher = new FakeWorkerLauncher();
        $pool = new WorkerPool(2, $launcher, maxWorkers: 2);
        $queue = new RequestQueue();

        // Both busy, then one dies the way Dispatcher::watch() reports it:
        // markDead() now, reapDeadWorkers() later.
        $first = $pool->write($pool->getAvailable(), new Message(MessageType::REQUEST, 'req-1'));
        $second = $pool->write($pool->getAvailable(), new Message(MessageType::REQUEST, 'req-2'));
        $first->markDead();

        $this->assertSame(WorkerState::DEAD, $first->getState());
        $this->assertSame(2, $pool->count(), 'still tracked until reaped');
        $this->assertSame(1, $pool->countActive(), 'but not counted as a worker that is staying');

        // Work waiting, nothing idle - the autoscaler would like to grow,
        // but the pool is at maxWorkers even counting the dead one.
        $queue->enqueue(new Message(MessageType::REQUEST, 'req-3'));
        (new Autoscaler($pool, $queue, minWorkers: 1, maxWorkers: 2, step: 2, cooldownSeconds: 0.0))->check();

        $this->assertSame(2, $pool->count(), 'must not exceed maxWorkers by filling in for an unreaped worker');
        $this->assertNull($pool->getAvailable(), 'a DEAD worker is never dispatchable');

        $pool->stop();
    }
}
