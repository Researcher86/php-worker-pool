<?php

declare(strict_types=1);

namespace App\Metrics;

/**
 * Lifetime counters for requests Master has accepted from clients.
 *
 * requests_timeout (PendingRequestRegistry::timeoutCount()) and
 * requests_rejected (RequestQueue::rejectedCount()) already exist as
 * counters on the components that decide those outcomes - this only adds
 * the ones nothing else was already tracking: how many requests came in at
 * all, how many got a real answer, and how many failed for any other
 * reason (a worker crashing, or the Master shutting down mid-request).
 */
final class RequestMetrics
{
    private int $total = 0;

    private int $completed = 0;

    private int $failed = 0;

    // PHASES.md Phase 17's two remaining metrics, plus the end-to-end figure
    // they add up to. Separate on purpose: a p99 of 10s means something very
    // different depending on whether it was spent queued or executing.
    public private(set) DurationStat $queueWait;

    public private(set) DurationStat $execution;

    public private(set) DurationStat $endToEnd;

    public function __construct()
    {
        $this->queueWait = new DurationStat();
        $this->execution = new DurationStat();
        $this->endToEnd = new DurationStat();
    }

    public function recordReceived(): void
    {
        $this->total++;
    }

    public function recordCompleted(): void
    {
        $this->completed++;
    }

    public function recordFailed(): void
    {
        $this->failed++;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function completed(): int
    {
        return $this->completed;
    }

    public function failed(): int
    {
        return $this->failed;
    }
}
