<?php

declare(strict_types=1);

namespace App\Tests\Worker;

use App\Protocol\MessageType;
use App\Tests\Support\AwaitsReadyWorkers;
use App\Tests\Support\FakeClock;
use App\Worker\ForkedWorkerLauncher;
use App\Worker\WorkerPool;
use App\Worker\WorkerState;
use Closure;
use PHPUnit\Framework\TestCase;

/**
 * The readiness handshake: a fork is not a usable worker.
 *
 * A worker becomes dispatchable when IT says so, because the Master cannot
 * see when an application's warm-up finished - only the process doing the
 * warming can. Until that message lands the worker is STARTING, and the
 * transition table refuses to dispatch there.
 */
final class ReadinessTest extends TestCase
{
    use AwaitsReadyWorkers;

    private string $marker = '';

    protected function setUp(): void
    {
        $this->marker = sys_get_temp_dir() . '/readiness-' . getmypid() . '-' . uniqid() . '.txt';
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
    }

    public function testAForkedWorkerIsNotDispatchableUntilItReportsReady(): void
    {
        $pool = new WorkerPool(2);

        try {
            // Forked, present, counted - and still not something work may be
            // handed to.
            $this->assertSame(2, $pool->count());
            $this->assertNull($pool->getAvailable(), 'a worker must not be dispatchable before its handshake');

            foreach ($pool->all() as $worker) {
                $this->assertTrue($worker->isStarting());
            }

            $this->awaitReadyWorkers($pool);

            $this->assertNotNull($pool->getAvailable());
            $this->assertSame(2, $pool->countIdle());
        } finally {
            $pool->stop();
        }
    }

    /**
     * The reason the hook is here and not in the Master: it runs AFTER the
     * fork, in the child, so anything it opens belongs to that worker alone.
     *
     * This is what makes a database connection safe to open in it. A
     * connection opened in the Master and inherited through fork() would be
     * one socket shared by every worker - each writing into the other's
     * protocol stream. Opened here, each worker gets its own.
     */
    public function testBootstrapRunsInEachWorkerAndNeverInTheMaster(): void
    {
        $pool = new WorkerPool(3, new ForkedWorkerLauncher(null, null, $this->recordOwnPid()));

        try {
            $this->awaitReadyWorkers($pool);

            $ranIn = $this->recordedPids();
            sort($ranIn);
            $workerPids = array_keys($pool->all());
            sort($workerPids);

            $this->assertSame($workerPids, $ranIn, 'the bootstrap must run once per worker, in that worker');
            $this->assertNotContains(posix_getpid(), $ranIn, 'the Master must never run it');
        } finally {
            $pool->stop();
        }
    }

    /**
     * The ordering that makes the handshake worth having: everything the
     * warm-up does is finished before the worker can receive a request, so
     * no request ever pays for a cold process.
     */
    public function testBootstrapFinishesBeforeTheWorkerBecomesDispatchable(): void
    {
        $marker = $this->marker;
        $bootstrap = static function () use ($marker): void {
            usleep(300_000);
            file_put_contents($marker, 'warm');
        };

        $pool = new WorkerPool(1, new ForkedWorkerLauncher(null, null, $bootstrap));

        try {
            $this->assertNull($pool->getAvailable());
            $this->assertFileDoesNotExist($marker, 'the warm-up cannot have finished yet');

            $this->awaitReadyWorkers($pool);

            $this->assertNotNull($pool->getAvailable());
            $this->assertFileExists($marker, 'the worker announced itself before finishing its warm-up');
        } finally {
            $pool->stop();
        }
    }

    /**
     * A bootstrap that hangs - an unreachable database, say - would
     * otherwise cost one worker of capacity permanently, and silently:
     * nothing else in the system ever asks why a worker never did anything.
     */
    public function testAWorkerThatNeverReportsReadyIsTerminated(): void
    {
        $clock = new FakeClock(1_000.0);
        $pool = new WorkerPool(1, new FakeWorkerLauncher(starting: true), clock: $clock);

        $clock->advance(29.0);
        $this->assertSame(0, $pool->terminateStuckWorkers(60.0, 30.0), 'still within its bootstrap budget');

        $clock->advance(2.0);
        $this->assertSame(1, $pool->terminateStuckWorkers(60.0, 30.0));

        $pool->stop();
    }

    /** Without a bootstrap limit the sweep leaves STARTING workers alone, however long they take. */
    public function testNoBootstrapLimitMeansNoBootstrapTimeout(): void
    {
        $clock = new FakeClock(1_000.0);
        $pool = new WorkerPool(1, new FakeWorkerLauncher(starting: true), clock: $clock);

        $clock->advance(10_000.0);

        $this->assertSame(0, $pool->terminateStuckWorkers(60.0));

        $pool->stop();
    }

    /**
     * A STARTING worker is not "stuck on a request" - the execution limit
     * must not touch it, whatever its age. The two limits answer different
     * questions and the sweep keeps them apart.
     */
    public function testTheExecutionLimitDoesNotApplyToAWorkerStillStarting(): void
    {
        $clock = new FakeClock(1_000.0);
        $pool = new WorkerPool(1, new FakeWorkerLauncher(starting: true), clock: $clock);

        $clock->advance(10_000.0);

        $this->assertSame(0, $pool->terminateStuckWorkers(60.0, 20_000.0));

        $pool->stop();
    }

    public function testALateReadyDoesNotReviveADrainingWorker(): void
    {
        $pool = new WorkerPool(1, new FakeWorkerLauncher(starting: true));
        $pid = array_key_first($pool->all());
        $this->assertNotNull($pid);

        // Reload/scale-down can overtake a READY already on the wire.
        $pool->all()[$pid]->drain();
        $pool->markReady($pid);

        $this->assertSame(WorkerState::DRAINING, $pool->all()[$pid]->getState());
        $this->assertNull($pool->getAvailable(), 'a draining worker must not come back into rotation');

        $pool->stop();
    }

    public function testReadyForAWorkerAlreadyGoneIsIgnored(): void
    {
        $pool = new WorkerPool(1, new FakeWorkerLauncher(starting: true));

        // The reaper can remove a worker between its READY being sent and read.
        $pool->markReady(999_999);

        $this->assertSame(1, $pool->count());

        $pool->stop();
    }

    /** @return Closure(): void */
    private function recordOwnPid(): Closure
    {
        $marker = $this->marker;

        return static function () use ($marker): void {
            file_put_contents($marker, posix_getpid() . "\n", FILE_APPEND | LOCK_EX);
        };
    }

    /** @return list<int> */
    private function recordedPids(): array
    {
        $contents = (string) @file_get_contents($this->marker);

        return array_map(intval(...), array_filter(explode("\n", trim($contents)), strlen(...)));
    }
}
