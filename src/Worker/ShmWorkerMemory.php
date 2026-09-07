<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * WorkerMemory backed by what each worker publishes about itself (see
 * SharedTelemetry), rather than by what the Master can observe from outside.
 *
 * The obvious alternative - and what this replaced - is for the Master to
 * read /proc/<pid>/statm. Two things are wrong with that:
 *
 * 1. IT IS LINUX-ONLY. On macOS, or in a container without /proc, there is
 *    no reading at all, and the memory recycling limit is silently not
 *    enforced. A worker publishing its own number needs nothing from the OS
 *    but the shared segment.
 *
 * 2. IT MEASURES THE WRONG QUANTITY. statm reports RSS - which includes the
 *    pages a forked worker still shares with the Master, the opcache, and
 *    every mapping the process didn't ask for. What maxMemoryBytes is
 *    actually about is PHP heap growing inside a long-lived worker, and
 *    memory_get_usage(true) is that number exactly. RSS made the limit fire
 *    on baseline that never grows; this one fires on the leak.
 *
 * Null while a freshly forked worker hasn't published yet, which the
 * recycling policy already treats the way it treats any unmeasurable
 * worker - it doesn't enforce the limit rather than guessing at it.
 */
final readonly class ShmWorkerMemory implements WorkerMemory
{
    public function __construct(
        private SharedTelemetry $telemetry,
    ) {
    }

    public function measure(int $pid): ?int
    {
        return $this->telemetry->read($pid)?->memoryBytes;
    }
}
