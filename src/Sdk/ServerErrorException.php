<?php

declare(strict_types=1);

namespace App\Sdk;

use RuntimeException;

/**
 * The Master answered the request with an ERROR message instead of a
 * response: server_overloaded (backpressure), request_timeout (the worker
 * took too long), worker_crashed, handler_failed, server_shutting_down.
 *
 * Without this, call() returned the error payload as if it were a normal
 * result - a caller that didn't defensively check for an 'error' key would
 * treat "the server refused" as data. $error carries the machine-readable
 * code above; $payload the full error payload for anything extra.
 */
final class ServerErrorException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        /** @var array<string, mixed> */
        public readonly array $payload = [],
    ) {
        parent::__construct(sprintf('Server answered with error "%s"', $error));
    }
}
