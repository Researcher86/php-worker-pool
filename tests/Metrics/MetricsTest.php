<?php

declare(strict_types=1);

namespace App\Tests\Metrics;

use App\Metrics\DurationSummary;
use App\Metrics\Metrics;
use PHPUnit\Framework\TestCase;

final class MetricsTest extends TestCase
{
    /** format() matches PLAN.md Phase 17's own "Example Output" shape exactly. */
    public function testFormatMatchesThePlansExampleOutputShape(): void
    {
        $metrics = new Metrics(
            workersTotal: 8,
            workersIdle: 3,
            workersBusy: 5,
            workersCrashedTotal: 0,
            workersRecycledTotal: 12,
            workersTerminatedTotal: 3,
            workersDraining: 1,
            queueSize: 124,
            requestsTotal: 100_000,
            requestsCompleted: 99_800,
            requestsFailed: 150,
            requestsTimeout: 50,
            requestsRejected: 0,
            queueWait: new DurationSummary(count: 3, averageMs: 0.50, maxMs: 2.25),
            execution: new DurationSummary(count: 3, averageMs: 4.00, maxMs: 9.75),
            endToEnd: new DurationSummary(count: 3, averageMs: 4.50, maxMs: 12.00),
        );

        $formatted = $metrics->format();

        $this->assertStringContainsString('Worker Pool Status', $formatted);
        $this->assertStringContainsString(
            "Workers:\n  Total: 8\n  Idle: 3\n  Busy: 5\n  Draining: 1\n  Crashed (lifetime): 0\n  Recycled (lifetime): 12\n  Terminated (lifetime): 3",
            $formatted
        );
        $this->assertStringContainsString("Queue:\n  Pending: 124", $formatted);
        $this->assertStringContainsString(
            "Requests:\n  Total: 100000\n  Completed: 99800\n  Failed: 150\n  Timeout: 50",
            $formatted
        );

        // The split is the point: one end-to-end number can't tell a
        // saturated pool from a slow handler.
        $this->assertStringContainsString(
            "Latency (ms, over 3 completed):\n"
            . "  Queue wait: avg 0.50  max 2.25\n"
            . "  Execution:  avg 4.00  max 9.75\n"
            . "  Total:      avg 4.50  max 12.00",
            $formatted
        );
    }
}
