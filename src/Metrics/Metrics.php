<?php

declare(strict_types=1);

namespace App\Metrics;

/**
 * A point-in-time snapshot of the Worker Pool's observable state
 * (PHASES.md Phase 17). Deliberately doesn't cover the "Performance Metrics"
 * (request_duration, worker_processing_time) - both need timestamps this
 * codebase doesn't track anywhere yet (when a request was queued, when a
 * worker actually started on it), and nothing currently needs them enough
 * to justify adding that bookkeeping. Add them when something does.
 */
final readonly class Metrics
{
    public function __construct(
        public int $workersTotal,
        public int $workersIdle,
        public int $workersBusy,
        public int $workersCrashedTotal,
        public int $workersRecycledTotal,
        public int $workersTerminatedTotal,
        public int $workersDraining,
        public int $queueSize,
        public int $requestsTotal,
        public int $requestsCompleted,
        public int $requestsFailed,
        public int $requestsTimeout,
        public int $requestsRejected,
        // Accepted and not finished yet: still queued, or with a worker
        // right now. The one term that makes the counters above a
        // PARTITION of requestsTotal rather than four numbers that happen
        // to be smaller than it - see requestsAccountedFor().
        public int $requestsPending,
        public DurationSummary $queueWait,
        public DurationSummary $execution,
        public DurationSummary $endToEnd,
    ) {
    }

    /**
     * The invariant the request counters are supposed to satisfy: every
     * request the Master ever accepted is in exactly one of five buckets -
     * answered, failed, timed out, rejected, or still in flight - so this
     * must equal requestsTotal in every snapshot, at any moment.
     *
     * It is stated here, in one place, because the five are counted across
     * three components with no view of each other (RequestMetrics,
     * PendingRequestRegistry, RequestQueue): nothing else in the system is
     * in a position to notice if a request ever fell out of all of them, or
     * got counted into two.
     */
    public function requestsAccountedFor(): int
    {
        return $this->requestsCompleted
            + $this->requestsFailed
            + $this->requestsTimeout
            + $this->requestsRejected
            + $this->requestsPending;
    }

    /** Matches PHASES.md Phase 17's own "Example Output" shape. */
    public function format(): string
    {
        return sprintf(
            <<<'TEXT'
            Worker Pool Status

            Workers:
              Total: %d
              Idle: %d
              Busy: %d
              Draining: %d
              Crashed (lifetime): %d
              Recycled (lifetime): %d
              Terminated (lifetime): %d

            Queue:
              Pending: %d

            Requests:
              Total: %d
              Completed: %d
              Failed: %d
              Timeout: %d
              Rejected: %d
              In flight: %d

            Latency (ms, over %d completed):
              Queue wait: avg %.2f  max %.2f
              Execution:  avg %.2f  max %.2f
              Total:      avg %.2f  max %.2f

            TEXT,
            $this->workersTotal,
            $this->workersIdle,
            $this->workersBusy,
            $this->workersDraining,
            $this->workersCrashedTotal,
            $this->workersRecycledTotal,
            $this->workersTerminatedTotal,
            $this->queueSize,
            $this->requestsTotal,
            $this->requestsCompleted,
            $this->requestsFailed,
            $this->requestsTimeout,
            $this->requestsRejected,
            $this->requestsPending,
            $this->endToEnd->count,
            $this->queueWait->averageMs,
            $this->queueWait->maxMs,
            $this->execution->averageMs,
            $this->execution->maxMs,
            $this->endToEnd->averageMs,
            $this->endToEnd->maxMs,
        );
    }
}
