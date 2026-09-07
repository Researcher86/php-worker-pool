<?php

declare(strict_types=1);

namespace App\Support;

final readonly class SystemClock implements Clock
{
    public function now(): float
    {
        return microtime(true);
    }
}
