<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * One worker's self-reported reading, as published into shared memory by the
 * worker itself and read back by the Master (see SharedTelemetry).
 *
 * Self-reported is the whole point: memory_get_usage() only ever describes
 * the process that calls it, so the number a worker knows about itself is
 * one the Master cannot obtain any other way - /proc gives it RSS, which is
 * a different quantity (see ShmWorkerMemory).
 */
final readonly class WorkerVitals
{
    public function __construct(
        /** The worker that published this - checked against the pid asked for, since slots are reused. */
        public int $pid,
        /** Bytes PHP has allocated from the OS inside that worker (memory_get_usage(true)). */
        public int $memoryBytes,
        /** When the worker last published, on the same wall clock both processes read. */
        public float $updatedAt,
    ) {
    }
}
