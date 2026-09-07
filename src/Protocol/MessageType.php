<?php

declare(strict_types=1);

namespace App\Protocol;

enum MessageType: string
{
    case REQUEST = 'request';
    case RESPONSE = 'response';
    case SHUTDOWN = 'shutdown';
    case ERROR = 'error';
    /**
     * Worker -> Master, once, before it can be dispatched to: "my bootstrap
     * finished, send me work". The only message a worker sends that isn't an
     * answer to something - see WorkerProcess's STARTING state.
     */
    case READY = 'ready';
}
