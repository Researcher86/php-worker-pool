<?php

declare(strict_types=1);

namespace App\Tests\Metrics;

use App\Metrics\RequestMetrics;
use PHPUnit\Framework\TestCase;

final class RequestMetricsTest extends TestCase
{
    public function testStartsAtZero(): void
    {
        $metrics = new RequestMetrics();

        $this->assertSame(0, $metrics->total());
        $this->assertSame(0, $metrics->completed());
        $this->assertSame(0, $metrics->failed());
    }

    public function testEachRecordMethodTracksItsOwnCounterIndependently(): void
    {
        $metrics = new RequestMetrics();

        $metrics->recordReceived();
        $metrics->recordReceived();
        $metrics->recordReceived();

        $metrics->recordCompleted();

        $metrics->recordFailed();
        $metrics->recordFailed();

        $this->assertSame(3, $metrics->total());
        $this->assertSame(1, $metrics->completed());
        $this->assertSame(2, $metrics->failed());
    }
}
