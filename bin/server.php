<?php

use App\Master\Master;

require __DIR__ . '/../vendor/autoload.php';

// WORKER_POOL_SOCKET lets a test (or a second instance) run on its own
// socket path without editing anything; unset, the default path applies.
$socketPath = getenv('WORKER_POOL_SOCKET');

$master = $socketPath !== false && $socketPath !== ''
    ? new Master(socketPath: $socketPath)
    : new Master();

$master->run();
