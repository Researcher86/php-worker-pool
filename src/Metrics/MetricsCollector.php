<?php

declare(strict_types=1);

namespace App\Metrics;

use App\Client\PendingRequestRegistry;
use App\Queue\RequestQueue;
use App\Worker\WorkerPool;

/**
 * Pulls a Metrics snapshot together from whichever component already owns
 * each number - this class holds none of the state itself, just the
 * references needed to read it.
 */
final readonly class MetricsCollector
{
    public function __construct(
        private WorkerPool $pool,
        private RequestQueue $queue,
        private PendingRequestRegistry $pendingRequests,
        private RequestMetrics $requests,
    ) {
    }

    public function snapshot(): Metrics
    {
        return new Metrics(
            workersTotal: $this->pool->count(),
            workersIdle: $this->pool->countIdle(),
            workersBusy: $this->pool->countBusy(),
            workersCrashedTotal: $this->pool->totalCrashed(),
            workersRecycledTotal: $this->pool->totalRecycled(),
            workersDraining: $this->pool->countDraining(),
            queueSize: $this->queue->size(),
            requestsTotal: $this->requests->total(),
            requestsCompleted: $this->requests->completed(),
            requestsFailed: $this->requests->failed(),
            requestsTimeout: $this->pendingRequests->timeoutCount(),
            requestsRejected: $this->queue->rejectedCount(),
        );
    }
}
