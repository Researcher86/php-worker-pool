<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * A worker's own writable slot in the shared segment - the only thing a
 * worker process ever holds of SharedTelemetry, and the only handle in the
 * system that writes into it.
 *
 * One writer per slot, forever: the slot is reserved by the Master before a
 * fork and belongs from then on to exactly one child, which is what makes
 * publishing safe without a lock (see SharedTelemetry's seqlock note).
 */
final readonly class TelemetrySlot
{
    public function __construct(
        private SharedTelemetry $telemetry,
        public int $index,
    ) {
    }

    /**
     * Publishes this process's current reading. Cheap by design - three
     * small writes into memory already mapped - because it runs once per
     * request on the worker's hot path.
     */
    public function publish(int $memoryBytes, float $now): void
    {
        $this->telemetry->write($this->index, posix_getpid(), $memoryBytes, $now);
    }
}
