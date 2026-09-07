<?php

declare(strict_types=1);

use App\Contract\Calculate\CalculateAction;
use App\Contract\Calculate\CalculateRequest;
use App\Master\Master;
use App\Protocol\Request;
use App\Protocol\Response;
use App\Protocol\PayloadHydrator;
use App\Worker\RecyclingPolicy;

require __DIR__ . '/../vendor/autoload.php';

/**
 * bin/server.php with the lifecycle machinery turned up to a rate a test can
 * actually observe: workers recycled every few requests, a short execution
 * limit, and a small pool that has to scale. Used by the end-to-end chaos
 * test so that recycling, reload, crash recovery and autoscaling all churn
 * within one run instead of once an hour.
 */
$socketPath = getenv('WORKER_POOL_SOCKET');

$handler = static fn (Request $request): Response => match ($request->action) {
    'calculate' => Response::of((new CalculateAction())(
        PayloadHydrator::hydrate(CalculateRequest::class, $request->params)
    )),
    default => Response::error('unknown_action'),
};

(new Master(
    socketPath: $socketPath !== false && $socketPath !== '' ? $socketPath : '/tmp/php-worker-pool-chaos.sock',
    minWorkers: 2,
    maxWorkers: 6,
    requestTimeoutSeconds: 10.0,
    workerExecutionTimeoutSeconds: 15.0,
    recycling: new RecyclingPolicy(maxRequests: 7),
    handler: $handler,
))->run();
