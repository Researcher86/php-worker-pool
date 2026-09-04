<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * /proc-backed WorkerMemory: reads resident pages out of /proc/<pid>/statm
 * and multiplies by the page size.
 *
 * Cheap enough to poll once per tick per worker - statm is a synthetic file
 * the kernel formats on read, no disk involved - but deliberately quiet
 * about failure: a pid that just exited, or a system without /proc, gives
 * null rather than an error, because a missing reading must never be able to
 * take down a Master that was only trying to decide whether to recycle.
 */
final class ProcMemory implements WorkerMemory
{
    private readonly int $pageSize;

    public function __construct(?int $pageSize = null)
    {
        // Not a constant across platforms; 4 KiB everywhere this actually
        // runs, and only used where /proc exists at all.
        $this->pageSize = $pageSize ?? 4096;
    }

    public function measure(int $pid): ?int
    {
        $statm = @file_get_contents('/proc/' . $pid . '/statm');

        if ($statm === false) {
            return null;
        }

        $fields = explode(' ', trim($statm));

        if (!isset($fields[1]) || !ctype_digit($fields[1])) {
            return null;
        }

        return ((int) $fields[1]) * $this->pageSize;
    }
}
