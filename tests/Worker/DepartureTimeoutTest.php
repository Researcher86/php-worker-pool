<?php

declare(strict_types=1);

namespace App\Tests\Worker;

use App\Protocol\Message;
use App\Protocol\MessageType;
use App\Tests\Support\FakeClock;
use App\Worker\WorkerPool;
use App\Worker\WorkerProcess;
use App\Worker\WorkerState;
use PHPUnit\Framework\TestCase;

/**
 * The third deadline in the pool, next to the execution and bootstrap ones
 * (ExecutionTimeoutTest, ReadinessTest): how long a worker gets between
 * being told to leave and actually being gone.
 *
 * Every other way out of the pool is bounded by something. A request has the
 * execution limit, a bootstrap has its own, a crash is reaped and replaced
 * within a tick. Retirement had nothing: retireIdleWorkers() sends SHUTDOWN,
 * closes the socket and then simply waits for a SIGCHLD that a worker
 * ignoring both would never send - and every sweep in the pool skipped it
 * for being on its way out. One slot, one pid and one telemetry slot,
 * leaked for the lifetime of the Master, with nothing anywhere asking why.
 */
final class DepartureTimeoutTest extends TestCase
{
    public function testARetiredWorkerIsLeftAloneWhileItIsWithinTheDepartureLimit(): void
    {
        $clock = new FakeClock(1_000.0);
        $pool = new WorkerPool(2, new FakeWorkerLauncher(), clock: $clock);

        $this->assertSame(1, $pool->scaleDown(1)); // drains an idle worker, and retires it on the spot
        $retired = $this->onlyWorkerInState($pool, WorkerState::STOPPING);

        $clock->advance(9.0);

        $this->assertSame(0, $pool->terminateStuckWorkers(60.0, 30.0, 10.0));
        $this->assertFalse($retired->isTerminating(), 'a worker that is simply still exiting must not be signalled');

        $pool->stop();
    }

    /**
     * A worker whose process ignores SHUTDOWN, ignores the EOF that follows
     * it, and just keeps running. SIGTERM once, SIGKILL on the next sweep -
     * the same escalation a stuck request gets, since by this point the two
     * mean the same thing.
     */
    public function testARetiredWorkerThatNeverExitsIsSignalledThenEscalated(): void
    {
        $clock = new FakeClock(1_000.0);
        $pool = new WorkerPool(2, new FakeWorkerLauncher(), clock: $clock);

        $pool->scaleDown(1);
        $retired = $this->onlyWorkerInState($pool, WorkerState::STOPPING);

        $clock->advance(11.0);

        $this->assertSame(1, $pool->terminateStuckWorkers(60.0, 30.0, 10.0));
        $this->assertTrue($retired->isTerminating());

        // Already signalled: escalate (SIGKILL), don't count it twice.
        $this->assertSame(0, $pool->terminateStuckWorkers(60.0, 30.0, 10.0));
        $this->assertTrue($retired->isTerminating());

        $pool->stop();
    }

    /**
     * The clock runs from the FIRST step of the departure, not the last. A
     * worker that is drained and then retired a moment later is one
     * departure - restarting the count at each step would let a worker that
     * keeps being nudged along never look overdue.
     */
    public function testTheDepartureClockStartsAtTheDrainNotAtTheRetirement(): void
    {
        $clock = new FakeClock(1_000.0);
        // One worker, so the only thing leaving in this test is the one it
        // is about.
        $pool = new WorkerPool(1, new FakeWorkerLauncher(), maxWorkers: 4, clock: $clock);

        // Drained while busy, so retireIdleWorkers() can't stop it yet.
        $busy = $pool->write($pool->getAvailable(), new Message(MessageType::REQUEST, 'req-1'));
        $pool->reload();

        $clock->advance(8.0);
        $busy->finishRequest();
        $pool->retireIdleWorkers(); // NOW it goes to STOPPING, 8s into leaving

        $this->assertSame(WorkerState::STOPPING, $busy->getState());

        $clock->advance(3.0); // 11s since the drain, 3s since the retirement

        $this->assertSame(1, $pool->terminateStuckWorkers(60.0, 30.0, 10.0));
        $this->assertTrue($busy->isTerminating());

        $pool->stop();
    }

    /**
     * No limit configured, no killing: the departure bound is opt-in, and a
     * Master that doesn't pass one behaves exactly as before.
     */
    public function testWithNoDepartureLimitNothingIsEverSignalledForLeavingSlowly(): void
    {
        $clock = new FakeClock(1_000.0);
        $pool = new WorkerPool(2, new FakeWorkerLauncher(), clock: $clock);

        $pool->scaleDown(1);
        $clock->advance(10_000.0);

        $this->assertSame(0, $pool->terminateStuckWorkers(60.0, 30.0));

        $pool->stop();
    }

    /**
     * A drained worker that never said READY has no request to overrun and
     * no bootstrap left to finish - it is simply leaving, so the departure
     * limit is the one that judges it.
     */
    public function testADrainedWorkerThatNeverBecameReadyIsAlsoBounded(): void
    {
        $clock = new FakeClock(1_000.0);
        $launcher = new FakeWorkerLauncher(starting: true);
        $pool = new WorkerPool(1, $launcher, maxWorkers: 2, clock: $clock);

        $pool->reload(); // drains the STARTING worker; nothing will ever answer for it
        $draining = $this->onlyWorkerInState($pool, WorkerState::STOPPING);

        $clock->advance(11.0);

        $this->assertSame(1, $pool->terminateStuckWorkers(60.0, 30.0, 10.0));
        $this->assertTrue($draining->isTerminating());

        $pool->stop();
    }

    private function onlyWorkerInState(WorkerPool $pool, WorkerState $state): WorkerProcess
    {
        $matching = array_values(array_filter($pool->all(), static fn ($worker) => $worker->getState() === $state));

        $this->assertCount(1, $matching, 'exactly one worker should be in ' . $state->name);

        return $matching[0];
    }
}
