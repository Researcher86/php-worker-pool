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
