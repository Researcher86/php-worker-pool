<?php

declare(strict_types=1);

namespace App\Worker;

use App\Queue\RequestQueue;

/**
 * PLAN.md Phase 20: grows the pool when the queue has work waiting and no
 * worker is free to take it, shrinks it back down when there's idle
 * capacity to spare - bounded by [$minWorkers, $maxWorkers] either way.
 *
 * Uses the two signals PLAN.md's own example describes (queue growing / low
 * load) via state already cheap to read: RequestQueue::size() and
 * WorkerPool's busy/idle counts (Phase 17). "Worker Utilization" and
 * "Request Latency" are listed there only as other *possible* signals, not
 * required ones - latency in particular would need per-request timing this
 * codebase deliberately doesn't track anywhere yet (see Phase 17's own
 * scope notes on request_duration).
 *
 * Meant to be polled periodically (Master calls check() once per tick,
 * alongside its other per-tick sweeps) rather than triggered by an event.
 */
final class Autoscaler
{
    private float $lastScaledAt = 0.0;

    public function __construct(
        private readonly WorkerPool $pool,
        private readonly RequestQueue $queue,
        private readonly int $minWorkers = 2,
        private readonly int $maxWorkers = 16,
        private readonly int $step = 2,
        // Minimum time between two scaling actions, so a single burst
        // doesn't cause a scale-up immediately followed by a scale-down (or
        // vice versa) before the previous change has had any chance to
        // matter.
        private readonly float $cooldownSeconds = 5.0,
    ) {
    }

    public function check(): void
    {
        $now = microtime(true);

        if ($now - $this->lastScaledAt < $this->cooldownSeconds) {
            return;
        }

        $total = $this->pool->count();
        // countIdle(), not count() - countBusy(): a retiring (STOPPING)
        // worker is neither busy nor available for dispatch, but count()
        // still includes it until reapDeadWorkers() catches up - subtracting
        // countBusy() from that would wrongly count it as spare capacity.
        $idle = $this->pool->countIdle();

        // Queue is growing and there's no spare capacity to absorb it.
        if (!$this->queue->isEmpty() && $idle === 0 && $total < $this->maxWorkers) {
            $this->pool->scaleUp(min($this->step, $this->maxWorkers - $total));
            $this->lastScaledAt = $now;

            return;
        }

        // Nothing queued and workers sitting idle above the floor - low load.
        if ($this->queue->isEmpty() && $idle > 0 && $total > $this->minWorkers) {
            $retired = $this->pool->scaleDown(min($this->step, $idle, $total - $this->minWorkers));

            if ($retired > 0) {
                $this->lastScaledAt = $now;
            }
        }
    }
}
