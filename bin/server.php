<?php

use App\Master\Master;
use App\Worker\HandlerAdapter;
use App\Worker\Request;
use App\Worker\Response;

require __DIR__ . '/../vendor/autoload.php';

// WORKER_POOL_SOCKET lets a test (or a second instance) run on its own
// socket path without editing anything; unset, the default path applies.
$socketPath = getenv('WORKER_POOL_SOCKET');

// Application-level types: each action is an ordinary typed function with
// its own request and result DTOs. The request DTO is hydrated from
// $request->params when the action is matched below - a payload that
// doesn't fit (missing key, wrong type) comes back to the client as
// invalid_payload, whether the mismatch is in the envelope or in the
// action's own DTO. The result DTO goes back out through Response::of(),
// which turns its public properties into the response payload.
final readonly class CalculateRequest
{
    public function __construct(
        public int $a,
        public int $b,
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

function calculate(CalculateRequest $request): CalculateResult
{
    return new CalculateResult($request->a + $request->b);
}

// What the workers actually DO, defined here - at server-configuration
// level - not inside the runtime. The runtime hydrates each payload into
// the Request envelope (action + params) before the call; the match routes
// to the action's function, hydrating params into that action's own DTO,
// and the Response it returns decides what the client sees - a result, or
// a named error. Everything else (framing, correlation ids, the error
// envelope when a handler throws) is the runtime's job; this closure
// reaches every worker, including ones forked long after startup, because
// fork() copies memory.
$handler = static function (Request $request): Response {
    return match ($request->action) {
        'calculate' => Response::of(calculate(HandlerAdapter::hydrate(CalculateRequest::class, $request->params))),
        // An unrecognized action is a real failure, not an empty answer: the
        // client gets an ERROR and the SDK throws ServerErrorException whose
        // ->error is exactly this code.
        default => Response::error('unknown_action'),
    };
};

$master = $socketPath !== false && $socketPath !== ''
    ? new Master(socketPath: $socketPath, handler: $handler)
    : new Master(handler: $handler);

$master->run();
