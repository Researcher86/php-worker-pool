<?php

declare(strict_types=1);

use PhpWorkerPool\Master\Master;
use PhpWorkerPool\Support\Logger;

require __DIR__ . '/bootstrap.php';

// WORKER_POOL_SOCKET lets a test (or a second instance) run on its own
// socket path without editing anything; unset, the default path applies.
$socketPath = getenv('WORKER_POOL_SOCKET');

// What the workers actually DO - see bin/handler.php.
$handler = require __DIR__ . '/handler.php';

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
$bootstrap = static function (Logger $logger): void {
    $startedAt = microtime(true);

    // The real thing would be something like:
    //     Database::connect('mysql:host=db;dbname=app', $user, $password);
    usleep(250_000);

    // The Master's own Logger, inherited through fork(): the warm-up reports
    // through the same channel, in the same format, as everything else the
    // runtime says - rather than each application picking its own stream.
    $logger->log(sprintf(
        'worker %d: warmed up in %.0fms, reporting ready',
        posix_getpid(),
        (microtime(true) - $startedAt) * 1000,
    ));
};

$master = $socketPath !== false && $socketPath !== ''
    ? new Master(socketPath: $socketPath, handler: $handler, bootstrap: $bootstrap)
    : new Master(handler: $handler, bootstrap: $bootstrap);

$master->run();
