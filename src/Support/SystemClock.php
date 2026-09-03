<?php

declare(strict_types=1);

namespace App\Support;

final class SystemClock implements Clock
{
    public function now(): float
    {
        return microtime(true);
    }
}
