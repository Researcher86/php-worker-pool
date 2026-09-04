<?php

declare(strict_types=1);

namespace App\Metrics;

/**
 * A point-in-time snapshot of the Worker Pool's observable state
 * (PLAN.md Phase 17). Deliberately doesn't cover the "Performance Metrics"
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
        public DurationSummary $queueWait,
        public DurationSummary $execution,
        public DurationSummary $endToEnd,
    ) {
    }

    /** Matches PLAN.md Phase 17's own "Example Output" shape. */
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
