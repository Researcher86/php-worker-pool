<?php

declare(strict_types=1);

namespace PhpWorkerPool\Tests\Support;

use PhpWorkerPool\Support\Clock;
use PhpWorkerPool\Support\SystemClock;

/**
 * The real time, plus a note of whether $signal was deliverable at the
 * moment it was asked. Clock readings happen all through the Master's own
 * code - the timeout sweep, the pool sweeps, the dispatcher - so this
 * counts how often that code ran with the signal free to interrupt it (see
 * Master\MasterSignalTest).
 */
final class SignalMaskProbeClock implements Clock
{
    public int $readings = 0;

    public int $readingsWithSignalUnblocked = 0;

    public function __construct(
        private readonly int $signal,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function now(): float
    {
        $this->readings++;

        // Asking for the mask means setting one: block, look at what it
        // was, put it back exactly.
        pcntl_sigprocmask(SIG_BLOCK, [$this->signal], $previous);
        pcntl_sigprocmask(SIG_SETMASK, $previous);

        if (!in_array($this->signal, $previous, true)) {
            $this->readingsWithSignalUnblocked++;
        }

        return $this->clock->now();
    }
}
