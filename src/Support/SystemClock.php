<?php

declare(strict_types=1);

namespace PhpWorkerPool\Support;

final readonly class SystemClock implements Clock
{
    public function now(): float
    {
        return microtime(true);
    }
}
