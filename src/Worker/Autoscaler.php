<?php

declare(strict_types=1);

namespace App\Worker;

use App\Queue\RequestQueue;
use App\Support\Clock;
use App\Support\SystemClock;

/**
 * PHASES.md Phase 20: grows the pool when the queue has work waiting and no
 * worker is free to take it, shrinks it back down when there's idle
 * capacity to spare - bounded by [$minWorkers, $maxWorkers] either way.
 *
 * Uses the two signals PHASES.md's own example describes (queue growing / low
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
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function check(): void
    {
        $now = $this->clock->now();

        if ($now - $this->lastScaledAt < $this->cooldownSeconds) {
            return;
        }

        // count() for the process ceiling (maxWorkers caps LIVE processes,
        // outgoing ones included); countActive() for the floor - right after
        // a reload() the pool briefly holds both generations, and judging
        // "too many idle workers" by count() would scale away the NEW
        // generation (the outgoing one is already excluded from
        // scaleDown()'s candidates, so it can only pick the fresh workers).
        $total = $this->pool->count();
        $active = $this->pool->countActive();
        // countIdle(), not count() - countBusy(): a retiring (STOPPING)
        // worker is neither busy nor available for dispatch, but count()
        // still includes it until reapDeadWorkers() catches up - subtracting
        // countBusy() from that would wrongly count it as spare capacity.
        $idle = $this->pool->countIdle();

        // Workers still warming up are capacity ON ITS WAY, not capacity
        // missing - and they are not counted as idle, so without this the
        // scaler reads a queue waiting on a bootstrap as a queue waiting on
        // too few workers. Measured before this line existed: a pool with a
        // floor of 2 and a 3s warm-up grew to 4 while its first two workers
        // were still connecting, forked two more that also had to connect,
        // and shrank back once they all reported ready - a burst of
        // database connections at exactly the moment a deploy is at its most
        // fragile. Wait for what is already coming before asking for more.
        $starting = $this->pool->countStarting();

        // Queue is growing and there's no spare capacity to absorb it.
        if (!$this->queue->isEmpty() && $idle === 0 && $starting === 0 && $total < $this->maxWorkers) {
            $this->pool->scaleUp(min($this->step, $this->maxWorkers - $total));
            $this->lastScaledAt = $now;

            return;
        }

        // Nothing queued and workers sitting idle above the floor - low load.
        if ($this->queue->isEmpty() && $idle > 0 && $active > $this->minWorkers) {
            $retired = $this->pool->scaleDown(min($this->step, $idle, $active - $this->minWorkers));

            if ($retired > 0) {
                $this->lastScaledAt = $now;
            }
        }
    }
}
