<?php

declare(strict_types=1);

namespace App\Tests\Worker;

use App\Protocol\Message;
use App\Protocol\MessageType;
use App\Tests\Support\FakeClock;
use App\Worker\WorkerPool;
use App\Worker\WorkerState;
use PHPUnit\Framework\TestCase;

/**
 * The pool-side half of timeouts (PHASES.md's "optionally terminate Worker").
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
     * Draining never interrupts a request, and this is what that means
     * precisely: a drained worker keeps its FULL execution budget, exactly
     * as if it had never been drained. It is retiring on purpose and will
     * leave on its own the moment it answers.
     */
    public function testADrainingWorkerKeepsItsWholeExecutionBudget(): void
    {
        $clock = new FakeClock(1_000.0);
        $pool = new WorkerPool(2, new FakeWorkerLauncher(), maxWorkers: 4, clock: $clock);

        $busyId = $pool->getAvailable();
        $worker = $pool->write($busyId, new Message(MessageType::REQUEST, 'req-1'));

        $pool->reload(); // drains the current generation, busy worker included

        $clock->advance(59.0);

        $this->assertSame(0, $pool->terminateStuckWorkers(60.0));
        $this->assertTrue($worker->isDraining(), 'a drained worker within the limit is left alone entirely');

        $pool->stop();
    }

    /**
     * Past the limit, though, the exemption stops making sense: "it will
     * leave on its own" is exactly the claim a request 61s into a 60s limit
     * has disproved. This used to be exempt unconditionally, which made a
     * drained worker with a hung handler the one thing in the pool nothing
     * could ever end - it held its slot, its pid and its telemetry slot for
     * as long as the Master lived.
     *
     * It is STOPPED rather than crashed, and that distinction is the point:
     * whoever drained it already launched its replacement, so an exit read
     * as a crash would fork a second one and leave the pool one worker over
     * its intended size.
     */
    public function testADrainingWorkerPastTheLimitIsStoppedRatherThanLeftForever(): void
    {
        $clock = new FakeClock(1_000.0);
        $pool = new WorkerPool(2, new FakeWorkerLauncher(), maxWorkers: 4, clock: $clock);

        $busyId = $pool->getAvailable();
        $worker = $pool->write($busyId, new Message(MessageType::REQUEST, 'req-1'));

        $pool->reload();
        $countAfterReload = $pool->count();

        $clock->advance(61.0);

        $this->assertSame(1, $pool->terminateStuckWorkers(60.0));
        $this->assertSame(WorkerState::STOPPING, $worker->getState(), 'a worker already leaving must exit as expected, not as a crash');
        $this->assertTrue($worker->isTerminating());
        $this->assertSame($countAfterReload, $pool->count(), 'signalling it must not launch anything new');

        // Second sweep escalates instead of counting it again - SIGKILL for
        // a worker that ignored the SIGTERM.
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
