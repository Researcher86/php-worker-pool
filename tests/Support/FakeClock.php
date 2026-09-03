<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\Clock;

/** Test double for Clock: time only moves when the test tells it to. */
final class FakeClock implements Clock
{
    public function __construct(
        private float $now = 0.0,
    ) {
    }

    public function now(): float
    {
        return $this->now;
    }

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }

    public function set(float $now): void
    {
        $this->now = $now;
    }
}
