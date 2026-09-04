<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * Reads another process's resident set size - the Master can't call
 * memory_get_usage() on a worker, since that only ever reports the caller's
 * own heap.
 *
 * Linux only, via /proc/<pid>/statm (field 2 is resident pages). Elsewhere -
 * macOS, or a hardened container without /proc - measure() returns null and
 * the memory limit simply isn't enforced; the request and lifetime limits,
 * which the Master counts itself, work everywhere.
 *
 * An interface so a test can supply readings without needing a real process
 * that has actually allocated anything.
 */
interface WorkerMemory
{
    /** Resident bytes for $pid, or null if unavailable on this platform. */
    public function measure(int $pid): ?int;
}
