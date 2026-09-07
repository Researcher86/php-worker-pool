<?php

declare(strict_types=1);

use App\Protocol\Request;
use App\Sdk\ServerErrorException;
use App\Sdk\WorkerPoolClient;
use RuntimeException;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Load generator for a running Master.
 *
 * wrk and k6 don't apply here - this isn't HTTP, it's a length-prefixed
 * protocol over a Unix domain socket - so the driver is the SDK itself,
 * which also means the numbers include the client-side cost a real caller
 * would actually pay.
 *
 * Concurrency comes from forked client processes rather than threads: PHP
 * has no threads here, and separate processes are what a real deployment
 * looks like anyway (N PHP-FPM workers all talking to the pool). Each child
 * runs its own client and reports back through a temp file.
 *
 * Usage:
 *   php bin/bench.php [--socket=PATH] [--clients=N] [--requests=N]
 *                     [--action=NAME] [--pipeline=N] [--timeout=SEC]
 *
 *   --clients    concurrent client processes            (default 4)
 *   --requests   requests each client sends             (default 500)
 *   --pipeline   requests in flight per client at once  (default 1)
 *   --action     action to call                         (default 'calculate')
 */

$options = getopt('', ['socket::', 'clients::', 'requests::', 'action::', 'pipeline::', 'timeout::']);

$socketPath = (string) ($options['socket'] ?? '/tmp/php-worker-pool.sock');
$clients = max(1, (int) ($options['clients'] ?? 4));
$perClient = max(1, (int) ($options['requests'] ?? 500));
$action = (string) ($options['action'] ?? 'calculate');
$pipeline = max(1, (int) ($options['pipeline'] ?? 1));
$timeout = (float) ($options['timeout'] ?? 10.0);

$resultDir = sys_get_temp_dir() . '/pwp-bench-' . getmypid();
mkdir($resultDir);

printf(
    "%d clients x %d requests (pipeline %d) -> %s\n",
    $clients,
    $perClient,
    $pipeline,
    $socketPath
);

$started = microtime(true);
$children = [];

for ($c = 0; $c < $clients; $c++) {
    $pid = pcntl_fork();

    if ($pid === -1) {
        fwrite(STDERR, "fork failed\n");
        exit(1);
    }

    if ($pid === 0) {
        // Child: drive one client, record each request's latency, write the
        // samples out for the parent to merge.
        $client = new WorkerPoolClient($socketPath, timeoutSeconds: $timeout);
        $latencies = [];
        $failed = 0;

        for ($sent = 0; $sent < $perClient; $sent += $pipeline) {
            $batch = min($pipeline, $perClient - $sent);
            $startedAt = microtime(true);

            try {
                if ($batch === 1) {
                    $client->call(new Request($action, ['a' => 1, 'b' => 2]));
                    $latencies[] = (microtime(true) - $startedAt) * 1000;
                } else {
                    $handles = [];
                    for ($i = 0; $i < $batch; $i++) {
                        $handles[] = $client->send(new Request($action, ['a' => 1, 'b' => 2]));
                    }
                    $client->all(...$handles);

                    // Per-request latency isn't meaningful inside a pipelined
                    // batch (they overlap), so charge each one the batch's
                    // average - honest for throughput, and the p-numbers stay
                    // comparable across pipeline depths.
                    $each = ((microtime(true) - $startedAt) * 1000) / $batch;
                    for ($i = 0; $i < $batch; $i++) {
                        $latencies[] = $each;
                    }
                }
            } catch (ServerErrorException | RuntimeException) {
                $failed += $batch;
            }
        }

        file_put_contents(
            $resultDir . '/' . getmypid() . '.json',
            json_encode(['latencies' => $latencies, 'failed' => $failed], JSON_THROW_ON_ERROR)
        );

        exit(0);
    }

    $children[] = $pid;
}

foreach ($children as $pid) {
    pcntl_waitpid($pid, $status);
}

$elapsed = microtime(true) - $started;

$latencies = [];
$failed = 0;

foreach (glob($resultDir . '/*.json') ?: [] as $file) {
    $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $latencies = [...$latencies, ...$data['latencies']];
    $failed += $data['failed'];
    unlink($file);
}

rmdir($resultDir);

sort($latencies);

/** @param list<float> $sorted */
$percentile = static function (array $sorted, float $p): float {
    if ($sorted === []) {
        return 0.0;
    }

    $index = (int) ceil(($p / 100) * count($sorted)) - 1;

    return $sorted[max(0, min($index, count($sorted) - 1))];
};

$total = $clients * $perClient;
$succeeded = count($latencies);

printf("\n");
printf("  duration        %.2fs\n", $elapsed);
printf("  requests        %d (%d failed)\n", $total, $failed);
printf("  throughput      %.0f req/s\n", $succeeded / $elapsed);
printf("  latency  p50    %.2f ms\n", $percentile($latencies, 50));
printf("  latency  p95    %.2f ms\n", $percentile($latencies, 95));
printf("  latency  p99    %.2f ms\n", $percentile($latencies, 99));
printf("  latency  max    %.2f ms\n", $latencies === [] ? 0.0 : end($latencies));
