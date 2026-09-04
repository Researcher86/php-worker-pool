<?php

declare(strict_types=1);

namespace App\Metrics;

/** A DurationStat frozen at snapshot time, in milliseconds. */
final readonly class DurationSummary
{
    public function __construct(
        public int $count,
        public float $averageMs,
        public float $maxMs,
    ) {
    }
}
