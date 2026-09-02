<?php

declare(strict_types=1);

namespace App\Worker;

enum WorkerState
{
    case STARTING;
    case IDLE;
    case BUSY;
    case STOPPING;
    case DEAD;
}
