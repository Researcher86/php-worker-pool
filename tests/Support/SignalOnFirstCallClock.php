<?php

declare(strict_types=1);

namespace PhpWorkerPool\Tests\Support;

use PhpWorkerPool\Support\Clock;
use PhpWorkerPool\Support\SystemClock;

/**
 * The real time, except that the first reading also sends $signal to the
 * calling process - see Master\MasterSignalTest for why that is the first
 * reading's exact position.
 */
final class SignalOnFirstCallClock implements Clock
{
    private bool $fired = false;

    public function __construct(
        private readonly int $signal,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function now(): float
    {
        if (!$this->fired) {
            $this->fired = true;
            posix_kill(posix_getpid(), $this->signal);
        }

        return $this->clock->now();
    }
}
