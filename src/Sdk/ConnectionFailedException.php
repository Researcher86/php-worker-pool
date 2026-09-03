<?php

declare(strict_types=1);

namespace App\Sdk;

/** Thrown by WorkerPoolClient when it can't connect to the Master's socket at all. */
final class ConnectionFailedException extends \RuntimeException
{
}
