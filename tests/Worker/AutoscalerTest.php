<?php

declare(strict_types=1);

namespace App\Tests\Worker;

use App\Protocol\Message;
use App\Protocol\MessageType;
use App\Queue\RequestQueue;
use App\Tests\Support\FakeClock;
use App\Worker\Autoscaler;
use App\Worker\WorkerPool;
use PHPUnit\Framework\TestCase;

final class AutoscalerTest extends TestCase
{
    /**
     * A queue waiting on a warm-up is not a queue waiting on more workers.
     * Workers in STARTING are capacity already on its way: scaling on top of
     * them double-counts the shortfall and forks processes that have to do
     * the same expensive bootstrap all over again.
     */
    public function testDoesNotGrowWhileWorkersAreStillWarmingUp(): void
    {
        $queue = new RequestQueue();
        $pool = new WorkerPool(2, new FakeWorkerLauncher(starting: true), maxWorkers: 16);
        $scaler = new Autoscaler($pool, $queue, 2, 16);

        $queue->enqueue(new Message(MessageType::REQUEST, 'req-1'));

        // No worker is idle - because both are still starting.
        $this->assertSame(0, $pool->countIdle());
        $this->assertSame(2, $pool->countStarting());

        $scaler->check();

        $this->assertSame(2, $pool->count(), 'capacity is coming; asking for more would double-count it');

        // Once they report ready the queue is served by the workers that
        // already existed, and there was never anything to scale.
        foreach (array_keys($pool->all()) as $pid) {
            $pool->markReady($pid);
        }

        $this->assertSame(2, $pool->countIdle());

        $pool->stop();
    }

    public function testScalesUpWhenQueueHasWorkAndNoIdleCapacity(): void
    {
        $pool = new WorkerPool(2, new FakeWorkerLauncher());
        $queue = new RequestQueue();

        foreach (['req-0', 'req-1'] as $id) {
            $pool->write($pool->getAvailable(), new Message(MessageType::REQUEST, $id));
        }

        $queue->enqueue(new Message(MessageType::REQUEST, 'queued-1'));

        $autoscaler = new Autoscaler($pool, $queue, minWorkers: 2, maxWorkers: 16, step: 2, cooldownSeconds: 0.0);
        $autoscaler->check();

        $this->assertSame(4, $pool->count());

        $pool->stop();
    }

    public function testDoesNotScaleUpPastMaxWorkers(): void
    {
        $pool = new WorkerPool(3, new FakeWorkerLauncher());
        $queue = new RequestQueue();

        foreach (['req-0', 'req-1', 'req-2'] as $id) {
            $pool->write($pool->getAvailable(), new Message(MessageType::REQUEST, $id));
        }

        $queue->enqueue(new Message(MessageType::REQUEST, 'queued-1'));

        $autoscaler = new Autoscaler($pool, $queue, minWorkers: 1, maxWorkers: 4, step: 5, cooldownSeconds: 0.0);
        $autoscaler->check();

        $this->assertSame(4, $pool->count()); // capped at maxWorkers, not 3 + 5

        $pool->stop();
    }

    /**
     * Regression test (found by a live SIGHUP run, not any earlier unit
     * test): right after reload() the pool briefly holds both generations -
     * the new one available, the outgoing one STOPPING until reaped. check()
     * used to judge "too many workers" by count() (which sees them all), and
     * since scaleDown() skips already-retiring workers, the only candidates
     * it could retire were the NEW generation - an idle pool's reload
     * deterministically scaled itself down to zero workers.
     */
    public function testDoesNotScaleDownTheFreshGenerationDuringAReload(): void
    {
        $pool = new WorkerPool(2, new FakeWorkerLauncher(), maxWorkers: 16);
        $queue = new RequestQueue();
        $autoscaler = new Autoscaler($pool, $queue, minWorkers: 2, maxWorkers: 16, step: 2, cooldownSeconds: 0.0);

        // Idle pool: reload() retires the old generation immediately, so the
        // pool now holds 2 fresh available workers + 2 STOPPING ones
        // awaiting their exit being reaped.
        $pool->reload();
        $this->assertSame(4, $pool->count());
        $this->assertSame(2, $pool->countActive());

        $autoscaler->check(); // used to scaleDown(2), killing the new generation

        $this->assertSame(2, $pool->countActive(), 'the fresh generation must survive the post-reload tick');
        $this->assertSame(2, $pool->countIdle(), 'the fresh generation must remain dispatchable');
        $this->assertNotNull($pool->getAvailable());

        $pool->stop();
    }

    public function testDoesNothingWhenThereIsIdleCapacityForTheQueue(): void
    {
        $pool = new WorkerPool(2, new FakeWorkerLauncher());
        $queue = new RequestQueue();
        $queue->enqueue(new Message(MessageType::REQUEST, 'queued-1'));

        // One worker busy, one still idle - no need to scale.
        $pool->write($pool->getAvailable(), new Message(MessageType::REQUEST, 'req-0'));

        $autoscaler = new Autoscaler($pool, $queue, minWorkers: 1, maxWorkers: 16, step: 2, cooldownSeconds: 0.0);
        $autoscaler->check();

        $this->assertSame(2, $pool->count());

        $pool->stop();
    }

    public function testScalesDownWhenIdleAboveMinimumAndQueueEmpty(): void
    {
        $pool = new WorkerPool(5, new FakeWorkerLauncher()); // all idle

        $autoscaler = new Autoscaler($pool, new RequestQueue(), minWorkers: 2, maxWorkers: 16, step: 2, cooldownSeconds: 0.0);
        $autoscaler->check();

        // -2 (step): usable workers drop immediately even though count()
        // (the raw total) won't until each retired one is actually reaped -
        // a real process exiting, which FakeWorkerLauncher never does.
        $this->assertSame(3, $pool->countIdle() + $pool->countBusy());

        $pool->stop();
    }

    public function testDoesNotScaleDownBelowMinWorkers(): void
    {
        $pool = new WorkerPool(3, new FakeWorkerLauncher());

        $autoscaler = new Autoscaler($pool, new RequestQueue(), minWorkers: 2, maxWorkers: 16, step: 5, cooldownSeconds: 0.0);
        $autoscaler->check();

        $this->assertSame(2, $pool->countIdle() + $pool->countBusy()); // capped at minWorkers, not 3 - 5

        $pool->stop();
    }

    public function testDoesNotScaleDownWhenAlreadyAtMinWorkers(): void
    {
        $pool = new WorkerPool(2, new FakeWorkerLauncher());

        $autoscaler = new Autoscaler($pool, new RequestQueue(), minWorkers: 2, maxWorkers: 16, step: 2, cooldownSeconds: 0.0);
        $autoscaler->check();

        $this->assertSame(2, $pool->count());

        $pool->stop();
    }

    public function testCooldownPreventsScalingTwiceInQuickSuccession(): void
    {
        $pool = new WorkerPool(5, new FakeWorkerLauncher());

        $autoscaler = new Autoscaler($pool, new RequestQueue(), minWorkers: 2, maxWorkers: 16, step: 1, cooldownSeconds: 5.0);

        $autoscaler->check(); // scales down by 1 -> 4
        $autoscaler->check(); // called immediately after - cooldown should block this one

        $this->assertSame(4, $pool->countIdle() + $pool->countBusy());

        $pool->stop();
    }

    /**
     * Same scenario as testCooldownPreventsScalingTwiceInQuickSuccession,
     * but proving the other half: once the cooldown has actually elapsed, a
     * second scaling action is allowed. A FakeClock is what makes this
     * checkable at all without a real 5-second sleep in the test suite.
     */
    public function testCooldownAllowsScalingAgainOnceItElapses(): void
    {
        $pool = new WorkerPool(5, new FakeWorkerLauncher());
        // Starts well above 0.0 - Autoscaler::$lastScaledAt itself defaults
        // to 0.0, so a clock starting at exactly 0.0 would make its very
        // first check() look like it's still within the cooldown window.
        $clock = new FakeClock(1_000.0);

        $autoscaler = new Autoscaler($pool, new RequestQueue(), minWorkers: 1, maxWorkers: 16, step: 1, cooldownSeconds: 5.0, clock: $clock);

        $autoscaler->check(); // scales down by 1 -> 4
        $clock->advance(5.1);
        $autoscaler->check(); // cooldown elapsed -> scales down again -> 3

        $this->assertSame(3, $pool->countIdle() + $pool->countBusy());

        $pool->stop();
    }
}
