<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * How much memory a worker is using, as the Master sees it - which it cannot
 * simply ask for: memory_get_usage() only ever reports the heap of whoever
 * calls it, and the Master is not the worker.
 *
 * Two ways to answer that, and they measure different quantities:
 * ShmWorkerMemory reads what the worker published about itself, ProcMemory
 * reads its resident set from /proc. Either may say null - a reading the
 * platform can't give, or a worker that hasn't reported one yet - and a
 * memory limit that can't be measured is left unenforced rather than
 * guessed at. The request and lifetime limits, which the Master counts
 * itself, work everywhere regardless.
 *
 * An interface so a test can supply readings without needing a real process
 * that has actually allocated anything.
 */
interface WorkerMemory
{
    /** Bytes in use by $pid, or null if no trustworthy reading is available. */
    public function measure(int $pid): ?int;
}
