<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Abstracts "what time is it" (matching microtime(true)'s float-seconds
 * convention, used everywhere in this codebase) behind an interface, so
 * anything that computes a deadline or measures elapsed time can be driven
 * deterministically in tests instead of depending on the real system clock
 * (see FakeClock).
 */
interface Clock
{
    public function now(): float;
}
