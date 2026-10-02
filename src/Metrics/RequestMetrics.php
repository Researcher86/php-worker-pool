<?php

declare(strict_types=1);

namespace PhpWorkerPool\Metrics;

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
    public private(set) int $total = 0;

    public private(set) int $completed = 0;

    public private(set) int $failed = 0;

    public function __construct(
        // PHASES.md Phase 17's two remaining metrics, plus the end-to-end
        // figure they add up to. Separate on purpose: a p99 of 10s means
        // something very different depending on whether it was spent queued
        // or executing.
        public readonly DurationStat $queueWait = new DurationStat(),
        public readonly DurationStat $execution = new DurationStat(),
        public readonly DurationStat $endToEnd = new DurationStat(),
    ) {
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
}
