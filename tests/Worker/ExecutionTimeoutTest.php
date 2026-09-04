<?php

declare(strict_types=1);

namespace App\Tests\Worker;

use App\Protocol\Message;
use App\Protocol\MessageType;
use App\Tests\Support\FakeClock;
use App\Worker\WorkerPool;
use PHPUnit\Framework\TestCase;

/**
 * The pool-side half of timeouts (PLAN.md's "optionally terminate Worker").
 * A request timeout answers the CLIENT; this answers the POOL - a handler
 * that never returns would otherwise hold its worker forever.
 */
final class ExecutionTimeoutTest extends TestCase
{
    public function testAWorkerIsLeftAloneWhileItIsWithinTheLimit(): void
    {
        $clock = new FakeClock(1_000.0);
        $pool = new WorkerPool(1, new FakeWorkerLauncher(), clock: $clock);

        $pool->write($pool->getAvailable(), new Message(MessageType::REQUEST, 'req-1'));

        $clock->advance(59.0);

        $this->assertSame(0, $pool->terminateStuckWorkers(60.0));
        $this->assertSame(0, $pool->totalTerminated());

        $pool->stop();
    }

    public function testAnIdleWorkerIsNeverTerminatedHoweverLongItHasExisted(): void
    {
        $clock = new FakeClock(1_000.0);
        $pool = new WorkerPool(1, new FakeWorkerLauncher(), clock: $clock);

        $clock->advance(10_000.0);

        // Idle, not stuck - the limit is about a single request's runtime,
        // not the worker's age (that's what recycling's maxLifetime is for).
        $this->assertSame(0, $pool->terminateStuckWorkers(60.0));

        $pool->stop();
    }

    public function testTheClockStartsAgainForEachRequest(): void
    {
        $clock = new FakeClock(1_000.0);
        $pool = new WorkerPool(1, new FakeWorkerLauncher(), clock: $clock);

        $workerId = $pool->getAvailable();

        $worker = $pool->write($workerId, new Message(MessageType::REQUEST, 'req-1'));
        $clock->advance(59.0);
        $worker->finishRequest();

        // A second request 59s later must get its own full budget rather
        // than inheriting the first one's elapsed time.
        $pool->write($workerId, new Message(MessageType::REQUEST, 'req-2'));
        $clock->advance(2.0);

        $this->assertSame(0, $pool->terminateStuckWorkers(60.0));

        $pool->stop();
    }

    /**
     * A drained worker is finishing its last request on purpose and will
     * leave on its own - killing it would turn an orderly retirement into a
     * lost request for no reason.
     */
    public function testADrainingWorkerIsExemptEvenPastTheLimit(): void
    {
        $clock = new FakeClock(1_000.0);
        $pool = new WorkerPool(2, new FakeWorkerLauncher(), maxWorkers: 4, clock: $clock);

        $busyId = $pool->getAvailable();
        $pool->write($busyId, new Message(MessageType::REQUEST, 'req-1'));

        $pool->reload(); // drains the current generation, busy worker included

        $clock->advance(120.0);

        $this->assertSame(0, $pool->terminateStuckWorkers(60.0));

        $pool->stop();
    }

    /**
     * SIGTERM first, SIGKILL on the next sweep for anything that survived -
     * and the kill is counted as a termination, not a crash, since the two
     * mean different things to whoever reads the metrics.
     */
    public function testAStuckWorkerIsSignalledOnceThenEscalated(): void
    {
        $clock = new FakeClock(1_000.0);
        $launcher = new FakeWorkerLauncher(); // no real process: posix_kill is a no-op on these pids
        $pool = new WorkerPool(1, $launcher, clock: $clock);

        $worker = $pool->write($pool->getAvailable(), new Message(MessageType::REQUEST, 'req-1'));
        $clock->advance(61.0);

        $this->assertSame(1, $pool->terminateStuckWorkers(60.0));
        $this->assertTrue($worker->isTerminating());

        // Second sweep escalates rather than counting it again - it was
        // already signalled, it just hasn't died yet.
        $this->assertSame(0, $pool->terminateStuckWorkers(60.0));
        $this->assertTrue($worker->isTerminating());

        $pool->stop();
    }
}
