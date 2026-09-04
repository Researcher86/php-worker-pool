<?php

use App\Master\Master;

require __DIR__ . '/../vendor/autoload.php';

// WORKER_POOL_SOCKET lets a test (or a second instance) run on its own
// socket path without editing anything; unset, the default path applies.
$socketPath = getenv('WORKER_POOL_SOCKET');

// Application-level DTOs: the handler below declares CalculateRequest as its
// parameter, so the runtime hydrates each request's payload array into it
// before the call (see HandlerAdapter) - a payload that doesn't fit comes
// back to the client as an invalid_payload error without the handler ever
// running. Returning a DTO works symmetrically: its public properties become
// the response payload.
final readonly class CalculateRequest
{
    /** @param array<string, int|float> $params */
    public function __construct(
        public string $action = '',
        public array $params = [],
    ) {
    }
}

final readonly class CalculateResult
{
    public function __construct(
        public int|float $result,
    ) {
    }
}

// What the workers actually DO, defined here - at server-configuration
// level - not inside the runtime. Everything else (framing, correlation
// ids, hydration, the error envelope when a handler throws) is the
// runtime's job; this closure reaches every worker, including ones forked
// long after startup, because fork() copies memory.
$handler = static function (CalculateRequest $request): CalculateResult|array {
    return match ($request->action) {
        'calculate' => new CalculateResult($request->params['a'] + $request->params['b']),
        // No action (or an unrecognized one) - echo the params back.
        default => $request->params,
    };
};

$master = $socketPath !== false && $socketPath !== ''
    ? new Master(socketPath: $socketPath, handler: $handler)
    : new Master(handler: $handler);

$master->run();
