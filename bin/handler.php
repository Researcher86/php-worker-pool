<?php

declare(strict_types=1);

use PhpWorkerPool\Contract\Calculate\CalculateAction;
use PhpWorkerPool\Contract\Calculate\CalculateRequest;
use PhpWorkerPool\Protocol\PayloadHydrator;
use PhpWorkerPool\Protocol\Request;
use PhpWorkerPool\Protocol\Response;

/**
 * The demo application's request handler, shared by every bin/ script that
 * starts a Master (server.php, chaos-server.php, client_and_server.php).
 *
 * What the workers actually DO, defined at server-configuration level - not
 * inside the runtime. The runtime hydrates each payload into the Request
 * envelope (action + params) before the call; the match routes to the
 * action's function, hydrating params into that action's own DTO, and the
 * Response it returns decides what the client sees - a result, or a named
 * error. Everything else (framing, correlation ids, the error envelope when
 * a handler throws) is the runtime's job; this closure reaches every worker,
 * including ones forked long after startup, because fork() copies memory.
 */
return static fn (Request $request): Response => match ($request->action) {
    'calculate' => Response::of(new CalculateAction()(PayloadHydrator::hydrate(CalculateRequest::class, $request->params))),
    // An unrecognized action is a real failure, not an empty answer: the
    // client gets an ERROR and the SDK throws ServerErrorException whose
    // ->error is exactly this code.
    default => Response::error('unknown_action'),
};
