<?php

declare(strict_types=1);

namespace App\Worker;

enum WorkerState
{
    case IDLE;
    case BUSY;
    /** Finishing its current request, if any, then leaving - see WorkerProcess::drain(). */
    case DRAINING;
    case STOPPING;
    case DEAD;
}
