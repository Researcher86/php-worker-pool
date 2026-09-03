<?php

declare(strict_types=1);

namespace App\Sdk;

/** Thrown by WorkerPoolClient when no response arrives within the configured timeout. */
final class RequestTimedOutException extends \RuntimeException
{
}
