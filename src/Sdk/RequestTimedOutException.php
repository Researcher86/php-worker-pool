<?php

declare(strict_types=1);

namespace App\Sdk;

use RuntimeException;

/** Thrown by WorkerPoolClient when no response arrives within the configured timeout. */
final class RequestTimedOutException extends RuntimeException
{
}
