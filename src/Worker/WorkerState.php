<?php

declare(strict_types=1);

namespace App\Worker;

enum WorkerState
{
    /**
     * Forked, but still running whatever the application does before it can
     * serve anything - connecting to a database, warming a cache. Leaves
     * only when the worker says READY. Nothing is dispatched here.
     */
    case STARTING;
    case IDLE;
    case BUSY;
    /** Finishing its current request, if any, then leaving - see WorkerProcess::drain(). */
    case DRAINING;
    case STOPPING;
    case DEAD;
}
