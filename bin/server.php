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

// Whatever an application must do ONCE PER WORKER before it can serve
// anything: open a database connection, prime a cache, load a routing table.
// Stubbed here with a sleep and a log line, because this repository has no
// database to connect to - a real one would look like the commented line.
//
// Three things about this hook, and all three are the reason it exists:
//
//   1. It runs in the CHILD, after fork(). That is what makes a connection
//      safe to open in it. A PDO handle opened out here, before the pool is
//      built, would be ONE socket inherited by every worker - each writing
//      into the others' MySQL protocol stream. Opened in here, each worker
//      gets its own.
//
//   2. So the hook must CREATE its resources, never capture them. Writing
//      `use ($pdo)` over a connection this script already opened puts the
//      shared-socket problem straight back, because the closure travels
//      through fork() with the handle inside it.
//
//   3. The worker reports READY only after this returns, and the Master
//      dispatches nothing to a worker that hasn't. So no request ever waits
//      on a cold process - not the first one after startup, and not the
//      first one to reach a replacement forked hours later.
//
// A warm-up that hangs (an unreachable database) doesn't cost a slot: past
// workerBootstrapTimeoutSeconds the worker is terminated and replaced.
$bootstrap = static function (): void {
    $startedAt = microtime(true);

    // The real thing would be something like:
    //     Database::connect('mysql:host=db;dbname=app', $user, $password);
    usleep(250_000);

    fwrite(STDERR, sprintf(
        "worker %d: warmed up in %.0fms, reporting ready\n",
        posix_getpid(),
        (microtime(true) - $startedAt) * 1000,
    ));
};

$master = $socketPath !== false && $socketPath !== ''
    ? new Master(socketPath: $socketPath, handler: $handler, bootstrap: $bootstrap)
    : new Master(handler: $handler, bootstrap: $bootstrap);

$master->run();
