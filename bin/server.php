<?php

declare(strict_types=1);

use App\Contract\Calculate\CalculateAction;
use App\Contract\Calculate\CalculateRequest;
use App\Master\Master;
use App\Protocol\Request;
use App\Protocol\Response;
use App\Protocol\PayloadHydrator;

require __DIR__ . '/../vendor/autoload.php';

// WORKER_POOL_SOCKET lets a test (or a second instance) run on its own
// socket path without editing anything; unset, the default path applies.
$socketPath = getenv('WORKER_POOL_SOCKET');

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
        'calculate' => Response::of(new CalculateAction()(PayloadHydrator::hydrate(CalculateRequest::class, $request->params))),
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
