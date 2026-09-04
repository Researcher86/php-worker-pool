<?php

declare(strict_types=1);

namespace App\Tests\Worker;

use App\Protocol\Message;
use App\Protocol\MessageType;
use App\Tests\Support\FakeClock;
use App\Worker\RecyclingPolicy;
use App\Worker\WorkerMemory;
use App\Worker\WorkerPool;
use App\Worker\WorkerState;
use PHPUnit\Framework\TestCase;

final class RecyclingTest extends TestCase
{
    /** Runs one request through $workerId and marks it answered. */
    private function handleOneRequest(WorkerPool $pool, int $workerId, string $requestId): void
    {
        $worker = $pool->write($workerId, new Message(MessageType::REQUEST, $requestId));
        $this->assertNotNull($worker);
        $worker->finishRequest();
    }

    public function testNoPolicyMeansNothingIsEverRecycled(): void
    {
        $pool = new WorkerPool(1, new FakeWorkerLauncher());
        $workerId = $pool->getAvailable();

        for ($i = 0; $i < 50; $i++) {
            $this->handleOneRequest($pool, $workerId, 'req-' . $i);
        }

        $this->assertSame(0, $pool->recycleExhaustedWorkers());
        $this->assertSame(0, $pool->totalRecycled());
        $this->assertSame(1, $pool->count());

        $pool->stop();
    }

    public function testWorkerIsReplacedOnceItHitsMaxRequests(): void
    {
        $pool = new WorkerPool(
            1,
            new FakeWorkerLauncher(),
            recycling: new RecyclingPolicy(maxRequests: 3),
        );

        $tiredId = $pool->getAvailable();
        $this->assertNotNull($tiredId);

        $this->handleOneRequest($pool, $tiredId, 'req-1');
        $this->handleOneRequest($pool, $tiredId, 'req-2');

        // Two of three - still in rotation.
        $this->assertSame(0, $pool->recycleExhaustedWorkers());
        $this->assertSame($tiredId, $pool->getAvailable());

        $this->handleOneRequest($pool, $tiredId, 'req-3');

        $this->assertSame(1, $pool->recycleExhaustedWorkers());
        $this->assertSame(1, $pool->totalRecycled());

        // Replaced, not just removed: a fresh worker is already serving, and
        // it isn't the tired one.
        $fresh = $pool->getAvailable();
        $this->assertNotNull($fresh);
        $this->assertNotSame($tiredId, $fresh);

        // A recycled worker is NOT a crash - the two are counted apart so a
        // healthy recycling rate never reads as instability.
        $this->assertSame(0, $pool->totalCrashed());

        $pool->stop();
    }

    public function testWorkerIsReplacedOnceItIsTooOld(): void
    {
        $clock = new FakeClock(1_000.0);
        $pool = new WorkerPool(
            1,
            new FakeWorkerLauncher(),
            recycling: new RecyclingPolicy(maxLifetime: 60.0),
            clock: $clock,
        );

        $oldId = $pool->getAvailable();

        $clock->advance(59.0);
        $this->assertSame(0, $pool->recycleExhaustedWorkers());

        $clock->advance(2.0);
        $this->assertSame(1, $pool->recycleExhaustedWorkers());
        $this->assertNotSame($oldId, $pool->getAvailable());

        $pool->stop();
    }

    public function testWorkerIsReplacedOnceItUsesTooMuchMemory(): void
    {
        $readings = new FixedMemory(10 * 1024 * 1024);
        $pool = new WorkerPool(
            1,
            new FakeWorkerLauncher(),
            recycling: new RecyclingPolicy(maxMemoryBytes: 256 * 1024 * 1024),
            memory: $readings,
        );

        $fatId = $pool->getAvailable();

        $this->assertSame(0, $pool->recycleExhaustedWorkers());

        $readings->bytes = 300 * 1024 * 1024;

        $this->assertSame(1, $pool->recycleExhaustedWorkers());
        $this->assertNotSame($fatId, $pool->getAvailable());

        $pool->stop();
    }

    /**
     * A platform that can't report another process's memory (no /proc -
     * macOS, some hardened containers) must simply not enforce that limit,
     * rather than recycling on a guess or blowing up.
     */
    public function testAnUnmeasurableMemoryLimitIsNotEnforced(): void
    {
        $pool = new WorkerPool(
            1,
            new FakeWorkerLauncher(),
            recycling: new RecyclingPolicy(maxMemoryBytes: 1),
            memory: new FixedMemory(null),
        );

        $this->assertSame(0, $pool->recycleExhaustedWorkers());
        $this->assertSame(1, $pool->count());

        $pool->stop();
    }

    /**
     * The whole reason recycling drains instead of killing: a worker over
     * its limit while BUSY keeps its request, answers it normally, and only
     * then exits - so a limit can be set aggressively without ever costing
     * a failed request.
     */
    public function testABusyWorkerOverItsLimitFinishesItsRequestFirst(): void
    {
        $launcher = new FakeWorkerLauncher();
        $pool = new WorkerPool(
            1,
            $launcher,
            recycling: new RecyclingPolicy(maxRequests: 1),
        );

        $tiredId = $pool->getAvailable();
        $this->handleOneRequest($pool, $tiredId, 'req-1');   // now at its limit

        // ...but it picks up one more before the sweep runs.
        $worker = $pool->write($tiredId, new Message(MessageType::REQUEST, 'req-2'));
        $this->assertNotNull($worker);

        $this->assertSame(1, $pool->recycleExhaustedWorkers());

        // Drained, NOT stopped: still holding req-2, socket still open.
        $this->assertSame(WorkerState::DRAINING, $worker->getState());
        $this->assertTrue($worker->isWorking());
        $this->assertSame('req-2', $worker->getCurrentRequestId());

        // The replacement is already serving, so the pool never runs short
        // while the tired one finishes.
        $this->assertNotNull($pool->getAvailable());
        $this->assertNotSame($tiredId, $pool->getAvailable());

        // req-2 is answered normally, and only then is the worker retired.
        $worker->finishRequest();
        $pool->retireIdleWorkers();

        $this->assertSame(WorkerState::STOPPING, $worker->getState());

        $pool->stop();
    }

    /**
     * If no replacement can be launched, the tired worker keeps serving
     * rather than the pool shrinking - recycling is an optimisation, and
     * losing capacity would be worse than running a stale worker a bit
     * longer. It stays over its limit, so the next sweep tries again.
     */
    public function testATiredWorkerKeepsServingWhenNoReplacementCanBeLaunched(): void
    {
        $fake = new FakeWorkerLauncher();
        $launcher = new FlakyWorkerLauncher($fake, failOnCall: 2, permanent: true);

        $pool = new WorkerPool(1, $launcher, recycling: new RecyclingPolicy(maxRequests: 1));

        $tiredId = $pool->getAvailable();
        $this->handleOneRequest($pool, $tiredId, 'req-1');

        $this->assertSame(0, $pool->recycleExhaustedWorkers());
        $this->assertSame(0, $pool->totalRecycled());

        // Still there, still dispatchable.
        $this->assertSame(1, $pool->count());
        $this->assertSame($tiredId, $pool->getAvailable());

        $pool->stop();
    }
}

/** A WorkerMemory that reports whatever the test sets, for every pid. */
final class FixedMemory implements WorkerMemory
{
    public function __construct(
        public ?int $bytes,
    ) {
    }

    public function measure(int $pid): ?int
    {
        return $this->bytes;
    }
}
