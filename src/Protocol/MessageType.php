<?php

declare(strict_types=1);

namespace App\Protocol;

enum MessageType: string
{
    case REQUEST = 'request';
    case RESPONSE = 'response';
    case SHUTDOWN = 'shutdown';
    case ERROR = 'error';
}
