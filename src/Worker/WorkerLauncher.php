<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * Starts a new worker and returns the master-side handle for it.
 *
 * Kept separate from WorkerPool so the pool's bookkeeping (which workers
 * exist, which are free, dispatching to them) doesn't need to know HOW a
 * worker comes to life — a real implementation forks an OS process, a test
 * double can hand back a WorkerProcess wired to a socket pair it controls
 * directly, with no process involved at all.
 */
interface WorkerLauncher
{
    public function launch(): WorkerProcess;
}
