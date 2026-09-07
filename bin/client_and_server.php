<?php

declare(strict_types=1);

use App\Contract\Calculate\CalculateAction;
use App\Contract\Calculate\CalculateRequest;
use App\Master\Master;
use App\Protocol\PayloadHydrator;
use App\Protocol\Request;
use App\Protocol\Response;
use App\Sdk\WorkerPoolClient;
use App\Support\Logger;

require __DIR__ . '/../vendor/autoload.php';

// A socket of this run's own, not the default path bin/server.php uses. Two
// Masters cannot share one socket - UnixSocketServer deliberately refuses to
// bind over a live one (see its removeStaleSocketFile()) - so a default path
// would make this script fail whenever `make run-server` is up, or whenever
// an earlier run of this same script left its Master behind. Worse than
// failing: the client would then be answered by that OTHER server and the
// run would look like it had worked.
$socketPath = sys_get_temp_dir() . '/php-worker-pool-demo-' . getmypid() . '.sock';

$serverPid = pcntl_fork();
if ($serverPid === -1) {
    fwrite(STDERR, "fork failed\n");
    exit(1);
}

if ($serverPid === 0) {
    $handler = static function (Request $request): Response {
        return match ($request->action) {
            'calculate' => Response::of(new CalculateAction()(PayloadHydrator::hydrate(CalculateRequest::class, $request->params))),
            // An unrecognized action is a real failure, not an empty answer: the
            // client gets an ERROR and the SDK throws ServerErrorException whose
            // ->error is exactly this code.
            default => Response::error('unknown_action'),
        };
    };

    // The per-worker warm-up, stubbed: it runs inside each forked worker,
    // before that worker reports READY, and nothing is dispatched to a
    // worker that hasn't reported. See bin/server.php for what belongs in
    // here and why it must open its resources rather than inherit them.
    //
    // Watch the output: every worker logs its own pid, and the request below
    // is answered only after one of them has finished warming up - which is
    // the whole point of the handshake, made visible.
    $bootstrap = static function (Logger $logger): void {
        usleep(250_000);

        $logger->log(sprintf('worker %d: warmed up, reporting ready', posix_getpid()));
    };

    // A Master that fails to start must say so and exit non-zero, or the
    // parent below would wait out its whole readiness budget with nothing to
    // report but a timeout.
    try {
        (new Master(socketPath: $socketPath, handler: $handler, bootstrap: $bootstrap))->run();
    } catch (Throwable $e) {
        fwrite(STDERR, 'server: ' . $e->getMessage() . "\n");

        exit(1);
    }

    exit(0);
}

/**
 * Blocks until the Master is accepting connections, rather than guessing at
 * a sleep() long enough to cover it. Also watches the child: a Master that
 * died on startup is reported as that, not as a connection timeout.
 */
$awaitServer = static function (string $socketPath, int $serverPid, float $timeoutSeconds = 10.0): void {
    $deadline = microtime(true) + $timeoutSeconds;

    while (microtime(true) < $deadline) {
        $probe = @stream_socket_client('unix://' . $socketPath, $errno, $errstr, 0.1);

        if ($probe !== false) {
            fclose($probe);

            return;
        }

        if (pcntl_waitpid($serverPid, $status, WNOHANG) === $serverPid) {
            fwrite(STDERR, sprintf("server exited during startup (status %d)\n", pcntl_wexitstatus($status)));

            exit(1);
        }

        usleep(50_000);
    }

    fwrite(STDERR, "server did not start within {$timeoutSeconds}s\n");
    posix_kill($serverPid, SIGKILL);
    pcntl_waitpid($serverPid, $status);

    exit(1);
};

$awaitServer($socketPath, $serverPid);

// finally, not a plain sequence: a request that throws (a timeout, an
// unknown action, a crashed pool) would otherwise skip the shutdown below
// and orphan the Master - which keeps holding its socket and its workers,
// and is exactly what makes the NEXT run fail for a reason that has nothing
// to do with the next run.
try {
    $client = new WorkerPoolClient($socketPath);

    // Timed on purpose: the Master accepts this connection immediately, but
    // the request waits in the queue until a worker has finished its
    // warm-up. The elapsed figure is the readiness handshake being paid for
    // once, here, instead of by whoever sends the first request.
    $startedAt = microtime(true);
    $response = $client->call(new Request('calculate', new CalculateRequest(a: 10, b: 20)));

    echo json_encode($response) . "\n";
    printf("answered in %.0fms (a cold worker had to warm up first)\n", (microtime(true) - $startedAt) * 1000);
} finally {
    posix_kill($serverPid, SIGTERM);
    pcntl_waitpid($serverPid, $status);
}
