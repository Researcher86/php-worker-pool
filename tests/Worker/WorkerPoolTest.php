<?php

declare(ticks = 1);

namespace App\Tests\Worker;

use App\Dispatcher\Dispatcher;
use App\EventLoop\EventLoop;
use App\IPC\ConnectionClosedException;
use App\Protocol\Message;
use App\Protocol\MessageType;
use App\Queue\RequestQueue;
use App\Worker\ForkedWorkerLauncher;
use App\Worker\WorkerPool;
use App\Worker\WorkerState;
use PHPUnit\Framework\TestCase;

final class WorkerPoolTest extends TestCase
{
    public function testStartsRequestedNumberOfWorkers(): void
    {
        $pool = new WorkerPool(4);

        $this->assertSame(4, $pool->count());

        $pool->stop();
    }

    /**
     * Regression test: a launch() failure partway through the constructor
     * (e.g. ForkedWorkerLauncher on a failed pcntl_fork()) used to propagate
     * straight out, leaving any already-launched workers as orphans with no
     * WorkerPool left to ever stop() them. The constructor must clean those
     * up itself before rethrowing.
     */
    public function testConstructorStopsAlreadyLaunchedWorkersIfALaterLaunchFails(): void
    {
        $fake = new FakeWorkerLauncher();
        $launcher = new FlakyWorkerLauncher($fake, failOnCall: 3, permanent: true);

        try {
            new WorkerPool(5, $launcher);
            $this->fail('expected the launch failure to propagate out of the constructor');
        } catch (\RuntimeException $e) {
            $this->assertSame('launch failed', $e->getMessage());
        }

        // Two workers launched successfully before the third call failed -
        // both must have been told to shut down (their master-side socket
        // closed) rather than left dangling as orphans.
        $workerEnds = $fake->workerEnds();
        $this->assertCount(2, $workerEnds);

        foreach ($workerEnds as $workerEnd) {
            $workerEnd->read(); // drain the SHUTDOWN message stop() sent before closing

            try {
                $workerEnd->read();
                $this->fail('expected the master-side socket to have been closed');
            } catch (ConnectionClosedException) {
                // expected - stop() closed its end
            }
        }
    }

    public function testMultipleWorkersProcessRequestsInParallel(): void
    {
        $pool = new WorkerPool(4);
        $loop = new EventLoop();
        $responses = [];

        $dispatcher = new Dispatcher(new RequestQueue(), $pool, $loop, function (Message $message) use (&$responses): void {
            $responses[] = $message;
        });

        foreach (['req-1', 'req-2', 'req-3', 'req-4'] as $id) {
            $dispatcher->dispatch(new Message(MessageType::REQUEST, $id));
        }

        // All four went to distinct workers at once - collect their answers.
        for ($i = 0; $i < 200 && count($responses) < 4; $i++) {
            $loop->tick(0.1);
        }

        $this->assertCount(4, $responses);

        $ids = array_column($responses, 'id');
        sort($ids);

        $this->assertSame(['req-1', 'req-2', 'req-3', 'req-4'], $ids);

        $pool->stop();
    }

    public function testGetAvailableReturnsIdleWorkerThenChangesWhenBusy(): void
    {
        $pool = new WorkerPool(1);
        $loop = new EventLoop();
        $responses = [];

        $workerId = $pool->getAvailable();
        $this->assertNotNull($workerId);

        $dispatcher = new Dispatcher(new RequestQueue(), $pool, $loop, function (Message $message) use (&$responses): void {
            $responses[] = $message;
        });

        $dispatcher->dispatch(new Message(MessageType::REQUEST, 'req', ['data' => 'x']));

        // Mid-request the only worker is BUSY - nothing available.
        $this->assertNull($pool->getAvailable());

        for ($i = 0; $i < 200 && count($responses) < 1; $i++) {
            $loop->tick(0.1);
        }

        $this->assertCount(1, $responses);
        $this->assertSame($workerId, $pool->getAvailable()); // idle again after answering

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

    /**
     * PLAN.md Phase 16's safety timeout: a worker that never reads the
     * SHUTDOWN message (stuck, or just too slow) must not hang shutdown
     * forever - stop() gives up on it and SIGKILLs it instead.
     */
    public function testStopKillsAWorkerThatNeverRespondsToShutdown(): void
    {
        $pool = new WorkerPool(1, new StuckWorkerLauncher());
        $stuckPid = $pool->getAvailable();
        $this->assertNotNull($stuckPid);

        $start = microtime(true);
        $pool->stop(0.3);
        $elapsed = microtime(true) - $start;

        // Proves the SIGKILL fallback actually fired rather than this test
        // blocking for the worker's full 60-second sleep.
        $this->assertLessThan(5.0, $elapsed);

        // posix_kill(..., 0) sends no signal, just checks the process still
        // exists - false means it's really gone.
        $this->assertFalse(posix_kill($stuckPid, 0));
    }

    /** PLAN.md Phase 17: workers_idle / workers_busy / workers_dead (lifetime). */
    public function testCountIdleAndCountBusyReflectWorkerState(): void
    {
        $launcher = new FakeWorkerLauncher();
        $pool = new WorkerPool(2, $launcher);

        $this->assertSame(2, $pool->countIdle());
        $this->assertSame(0, $pool->countBusy());

        $busyId = $pool->getAvailable();
        $this->assertNotNull($busyId);
        $pool->write($busyId, new Message(MessageType::REQUEST, 'req-1'));

        $this->assertSame(1, $pool->countIdle());
        $this->assertSame(1, $pool->countBusy());

        $pool->stop();
    }

    public function testTotalCrashedAccumulatesAcrossReapCalls(): void
    {
        $pool = new WorkerPool(2);
        $this->assertSame(0, $pool->totalCrashed());

        $firstVictim = $pool->getAvailable();
        posix_kill($firstVictim, SIGKILL);
        usleep(100_000);
        $pool->reapDeadWorkers();

        $this->assertSame(1, $pool->totalCrashed());

        // Kill whichever worker is available now (the replacement or the
        // other original one - either way, a second crash).
        $secondVictim = $pool->getAvailable();
        posix_kill($secondVictim, SIGKILL);
        usleep(100_000);
        $pool->reapDeadWorkers();

        $this->assertSame(2, $pool->totalCrashed());

        $pool->stop();
    }

    /**
     * PLAN.md Phase 19: reload() replaces every worker without dropping the
     * pool below its configured size or below its target once the old
     * generation is gone. All idle at reload time, so retiring finishes
     * immediately (still inside reload() itself).
     */
    public function testReloadReplacesIdleWorkersImmediately(): void
    {
        $launcher = new FakeWorkerLauncher();
        $pool = new WorkerPool(2, $launcher);

        $pool->reload();

        // Briefly inflated (old generation still present, DRAINING then
        // STOPPING, not yet reaped) - both exist at once during a reload.
        $this->assertSame(4, $pool->count());

        $pool->stop();
    }

    /**
     * The actual point of reload() being separate from stop(): a worker
     * mid-request when reload() is called keeps processing and answering
     * completely normally - retireIdleWorkers() must not touch it, or
     * finishRequest()/the in-flight response would break.
     */
    public function testReloadLeavesABusyWorkerAloneUntilItFinishes(): void
    {
        $launcher = new FakeWorkerLauncher();
        $pool = new WorkerPool(1, $launcher);

        $busyId = $pool->getAvailable();
        $this->assertNotNull($busyId);
        $worker = $pool->write($busyId, new Message(MessageType::REQUEST, 'req-1'));

        $pool->reload();
        $pool->retireIdleWorkers(); // no-op while it's still working

        // DRAINING, not BUSY: it will take no NEW request, but the one it
        // has is left completely alone - that combination is exactly what
        // the state exists to express.
        $this->assertSame(WorkerState::DRAINING, $worker->getState());
        $this->assertTrue($worker->isWorking());
        $this->assertSame('req-1', $worker->getCurrentRequestId()); // untouched by reload

        // The new generation is immediately usable, the retiring one is not.
        $this->assertNotSame($busyId, $pool->getAvailable());

        // Finishes its request normally, same as if no reload had happened -
        // and stays DRAINING rather than going back to IDLE, so it can't be
        // handed another one in the window before it's retired.
        $worker->finishRequest();
        $this->assertSame(WorkerState::DRAINING, $worker->getState());
        $this->assertFalse($worker->isWorking());
        $this->assertNull($pool->getAvailable() === $busyId ? $busyId : null);

        $pool->retireIdleWorkers();

        $this->assertSame(WorkerState::STOPPING, $worker->getState());

        $pool->stop();
    }

    /**
     * Regression test: reload() used to launch a full duplicate generation
     * unconditionally, which could momentarily double the pool past
     * maxWorkers once Autoscaler (Phase 20) could have already grown it
     * close to that ceiling. It must now cap the transient size instead.
     */
    public function testReloadDoesNotExceedMaxWorkersWhenPoolIsNearCapacity(): void
    {
        $launcher = new FakeWorkerLauncher();
        $pool = new WorkerPool(3, $launcher, maxWorkers: 4);

        $pool->reload();

        // Old behavior would have given 6 (3 outgoing + 3 replacements).
        $this->assertSame(4, $pool->count());

        $pool->stop();
    }

    public function testReloadWhileAlreadyRetiringIsANoOp(): void
    {
        $launcher = new FakeWorkerLauncher();
        $pool = new WorkerPool(1, $launcher);

        $busyId = $pool->getAvailable();
        $pool->write($busyId, new Message(MessageType::REQUEST, 'req-1'));

        $pool->reload(); // 1 old (busy, retiring) + 1 new = 2
        $this->assertSame(2, $pool->count());

        $pool->reload(); // old generation still retiring - must not stack another one
        $this->assertSame(2, $pool->count());

        $pool->stop();
    }

    /** PLAN.md Phase 20. */
    public function testScaleUpAddsWorkers(): void
    {
        $launcher = new FakeWorkerLauncher();
        $pool = new WorkerPool(2, $launcher);

        $pool->scaleUp(3);

        $this->assertSame(5, $pool->count());
        $this->assertSame(5, $pool->countIdle());

        $pool->stop();
    }

    public function testScaleDownRetiresOnlyIdleWorkersUpToTheRequestedCount(): void
    {
        $launcher = new FakeWorkerLauncher();
        $pool = new WorkerPool(3, $launcher);

        $busyId = $pool->getAvailable();
        $this->assertNotNull($busyId);
        $worker = $pool->write($busyId, new Message(MessageType::REQUEST, 'req-1'));

        // 2 idle, 1 busy - asking for more than the idle count only retires
        // what's actually safe to retire.
        $retired = $pool->scaleDown(5);

        $this->assertSame(2, $retired);
        $this->assertSame(WorkerState::BUSY, $worker->getState()); // left alone
        $this->assertSame('req-1', $worker->getCurrentRequestId());
        $this->assertSame(1, $pool->countIdle() + $pool->countBusy()); // only the busy one is still selectable

        $pool->stop();
    }

    public function testScaleDownReturnsZeroWhenNothingIsIdle(): void
    {
        $launcher = new FakeWorkerLauncher();
        $pool = new WorkerPool(1, $launcher);

        $busyId = $pool->getAvailable();
        $pool->write($busyId, new Message(MessageType::REQUEST, 'req-1'));

        $this->assertSame(0, $pool->scaleDown(1));

        $pool->stop();
    }

    /**
     * Regression test: advanceReload() (used by reload()) shifted a pid off
     * $pendingReload before calling launch() for its replacement - a
     * failure there (e.g. a transient fork() failure) used to lose that pid
     * entirely: no replacement launched, but nothing left pending either,
     * so it would just sit forever, neither retiring nor ever replaced.
     */
    public function testReloadSurvivesALaunchFailureAndKeepsTheWorkerPendingForRetry(): void
    {
        $launcher = new FlakyWorkerLauncher(new FakeWorkerLauncher(), failOnCall: 3, permanent: true); // construction uses calls 1-2
        $pool = new WorkerPool(2, $launcher);

        $pool->reload(); // call 3 fails immediately

        // Didn't crash, and didn't launch a replacement it can't account for.
        $this->assertSame(2, $pool->count());

        // A later trigger (the next SIGCHLD, in production) gets another
        // chance rather than reload() being permanently stuck - still fails
        // here too (this fake launcher never recovers), but must not throw.
        $pool->reapDeadWorkers();

        $this->assertSame(2, $pool->count());

        $pool->stop();
    }

    /** The other half: once launch() actually recovers, the retry completes the reload normally. */
    public function testReloadCompletesOnceARetriedLaunchSucceeds(): void
    {
        $launcher = new FlakyWorkerLauncher(new FakeWorkerLauncher(), failOnCall: 3); // construction uses calls 1-2
        $pool = new WorkerPool(2, $launcher);

        $pool->reload(); // call 3 fails, first pid requeued, second never attempted this round

        $this->assertSame(2, $pool->count());

        $pool->reapDeadWorkers(); // retries: calls 4 and 5 both succeed now

        // Both original workers replaced (they're still present, marked
        // STOPPING, until something actually reaps them - see the
        // FakeWorkerLauncher caveat elsewhere in this file).
        $this->assertSame(4, $pool->count());

        $pool->stop();
    }

    /**
     * Regression test: reapDeadWorkers()'s crash-replacement launch() had
     * no try/catch, so a failure there aborted its whole while loop - any
     * other dead worker from the same waitpid() batch was left unreaped and
     * unreported until the next SIGCHLD, instead of just that one
     * replacement being skipped.
     */
    public function testReapDeadWorkersProcessesTheWholeBatchEvenWhenOneReplacementLaunchFails(): void
    {
        // Pool of 3 real workers (launch calls 1-3). Two are killed at
        // once; their replacements are calls 4 and 5 - call 4 is made to
        // fail, call 5 succeeds.
        $launcher = new FlakyWorkerLauncher(new ForkedWorkerLauncher(), failOnCall: 4);
        $pool = new WorkerPool(3, $launcher);

        // write() marks a worker BUSY (excluded from getAvailable()), so
        // three calls in a row give three distinct pids.
        $id1 = $pool->getAvailable();
        $this->assertNotNull($id1);
        $pool->write($id1, new Message(MessageType::REQUEST, 'noop-1'));

        $id2 = $pool->getAvailable();
        $this->assertNotNull($id2);
        $pool->write($id2, new Message(MessageType::REQUEST, 'noop-2'));

        $id3 = $pool->getAvailable();
        $this->assertNotNull($id3);

        posix_kill($id1, SIGKILL);
        posix_kill($id2, SIGKILL);
        usleep(150_000);

        $crashes = $pool->reapDeadWorkers();

        // Both dead workers were reaped and reported - the loop wasn't
        // aborted by the first (failed) replacement attempt.
        $this->assertCount(2, $crashes);

        // 1 survivor (id3) + exactly 1 successful replacement - the other
        // failed and was skipped, not silently retried forever here.
        $this->assertSame(2, $pool->count());

        $pool->stop();
    }

    /**
     * Regression test: scaleUp()'s launch() had no try/catch either -
     * called from Autoscaler::check() in Master's main loop (not a signal
     * handler), so an uncaught failure there would have taken the whole
     * Master down instead of just under-fulfilling the scale-up request.
     */
    public function testScaleUpStopsEarlyWithoutThrowingWhenALaunchFails(): void
    {
        $launcher = new FlakyWorkerLauncher(new FakeWorkerLauncher(), failOnCall: 4, permanent: true); // construction uses calls 1-2
        $pool = new WorkerPool(2, $launcher);

        $launched = $pool->scaleUp(5); // call 3 succeeds, call 4 fails - stops there

        $this->assertSame(1, $launched);
        $this->assertSame(3, $pool->count());

        $pool->stop();
    }
}
