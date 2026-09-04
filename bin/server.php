<?php

use App\Master\Master;

require __DIR__ . '/../vendor/autoload.php';

// WORKER_POOL_SOCKET lets a test (or a second instance) run on its own
// socket path without editing anything; unset, the default path applies.
$socketPath = getenv('WORKER_POOL_SOCKET');

// What the workers actually DO, defined here - at server-configuration
// level - not inside the runtime: request payload in, response payload out.
// Everything else (framing, correlation ids, the error envelope when a
// handler throws) is the runtime's job; this closure reaches every worker,
// including ones forked long after startup, because fork() copies memory.
$handler = static function (array $payload): array {
    return match ($payload['action'] ?? null) {
        'calculate' => [
            'result' => $payload['params']['a'] + $payload['params']['b'],
        ],
        // No action (or an unrecognized one) - echo the payload back.
        default => $payload,
    };
};

$master = $socketPath !== false && $socketPath !== ''
    ? new Master(socketPath: $socketPath, handler: $handler)
    : new Master(handler: $handler);

$master->run();
