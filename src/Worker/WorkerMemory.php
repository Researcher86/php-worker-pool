<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * How much memory a worker is using, as the Master sees it - which it cannot
 * simply ask for: memory_get_usage() only ever reports the heap of whoever
 * calls it, and the Master is not the worker.
 *
 * So a worker reports it instead, and ShmWorkerMemory reads that back. A
 * reading can still be missing - a worker that hasn't published one yet -
 * and null says so, leaving the memory limit unenforced for that worker
 * rather than guessed at. The request and lifetime limits, which the Master
 * counts itself, are unaffected.
 *
 * An interface rather than the one class, because measure() is the seam a
 * test needs: RecyclingTest drives the whole recycling path off scripted
 * readings, with no process that has actually allocated anything.
 */
interface WorkerMemory
{
    /** Bytes in use by $pid, or null if no trustworthy reading is available. */
    public function measure(int $pid): ?int;
}
