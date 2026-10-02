<?php

declare(strict_types=1);

use PhpWorkerPool\Master\Master;
use PhpWorkerPool\Worker\RecyclingPolicy;

require __DIR__ . '/bootstrap.php';

/**
 * bin/server.php with the lifecycle machinery turned up to a rate a test can
 * actually observe: workers recycled every few requests, a short execution
 * limit, and a small pool that has to scale. Used by the end-to-end chaos
 * test so that recycling, reload, crash recovery and autoscaling all churn
 * within one run instead of once an hour.
 */
$socketPath = getenv('WORKER_POOL_SOCKET');

new Master(
    socketPath: $socketPath !== false && $socketPath !== '' ? $socketPath : '/tmp/php-worker-pool-chaos.sock',
    minWorkers: 2,
    maxWorkers: 6,
    requestTimeoutSeconds: 10.0,
    workerExecutionTimeoutSeconds: 15.0,
    recycling: new RecyclingPolicy(maxRequests: 7),
    handler: require __DIR__ . '/handler.php',
)->run();
