<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Contract\Calculate\CalculateRequest;
use App\Protocol\Request;
use App\Sdk\ConnectionFailedException;
use App\Sdk\ServerErrorException;
use App\Sdk\WorkerPoolClient;
use PHPUnit\Framework\TestCase;

/**
 * The scenarios that only a real Master with real forked workers can prove:
 * a worker killed mid-request, a whole generation replaced under SIGHUP
 * while requests are in flight, and a shutdown that has to deliver work it
 * had already accepted.
 *
 * Unit tests cover each mechanism in isolation with fakes; these check that
 * the mechanisms still hold when signals, forks and sockets are real and the
 * timing isn't chosen by the test.
 */
final class ChaosTest extends TestCase
{
    /** @var resource|null */
    private mixed $process = null;

    private string $socketPath = '';

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            if (proc_get_status($this->process)['running']) {
                proc_terminate($this->process, SIGKILL);
            }

            proc_close($this->process);
        }

        @unlink($this->socketPath);
    }

    private function startServer(): WorkerPoolClient
    {
        $this->socketPath = sys_get_temp_dir() . '/pwp-chaos-' . getmypid() . '-' . uniqid() . '.sock';

        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../../bin/server.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['WORKER_POOL_SOCKET' => $this->socketPath]
        );

        $this->assertIsResource($process);
        $this->process = $process;

        $client = new WorkerPoolClient($this->socketPath, timeoutSeconds: 10.0);

        // The server binds asynchronously to this test - retry until it
        // accepts, bounded so a server that never starts fails rather than
        // hangs.
        $deadline = microtime(true) + 10.0;
        while (true) {
            try {
                $client->call(new Request('calculate', new CalculateRequest(1, 1)));

                return $client;
            } catch (ConnectionFailedException $e) {
                if (microtime(true) >= $deadline) {
                    throw $e;
                }

                usleep(50_000);
            }
        }
    }

    private function masterPid(): int
    {
        $status = proc_get_status($this->process);

        return $status['pid'];
    }

    /** @return list<int> the pids the Master currently has forked */
    private function workerPids(): array
    {
        $output = shell_exec('pgrep -P ' . $this->masterPid() . ' 2>/dev/null') ?? '';
        $pids = array_values(array_filter(array_map('intval', explode("\n", trim($output)))));

        return $pids;
    }

    /** @return list<int> */
    private function waitForWorkers(int $count, float $timeoutSeconds = 10.0): array
    {
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            $pids = $this->workerPids();

            if (count($pids) >= $count) {
                return $pids;
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        $this->fail(sprintf('expected %d workers, saw %d', $count, count($this->workerPids())));
    }

    /** Test 1: many requests across the whole pool, every answer delivered and correct. */
    public function testEveryRequestInABurstIsAnswered(): void
    {
        $client = $this->startServer();

        $pending = [];
        for ($i = 1; $i <= 100; $i++) {
            $pending[] = $client->send(new Request('calculate', new CalculateRequest($i, $i)));
        }

        $results = $client->all(...$pending);

        $this->assertCount(100, $results);

        foreach ($results as $i => $result) {
            // Answers may come back in any order; all() puts them back in
            // the order asked for, which is exactly what's asserted here.
            $this->assertSame(['result' => ($i + 1) * 2], $result);
        }
    }

    /** Test 2: a worker killed outright is replaced and the pool keeps serving. */
    public function testKillingAWorkerIsRecoveredFrom(): void
    {
        $client = $this->startServer();

        $before = $this->waitForWorkers(2);
        posix_kill($before[0], SIGKILL);

        // The pool must return to strength on its own.
        $deadline = microtime(true) + 10.0;
        do {
            $after = $this->workerPids();
            $recovered = count($after) >= count($before) && !in_array($before[0], $after, true);
            usleep(50_000);
        } while (!$recovered && microtime(true) < $deadline);

        $this->assertTrue($recovered, 'the killed worker should have been replaced');
        $this->assertSame(
            ['result' => 30],
            $client->call(new Request('calculate', new CalculateRequest(10, 20))),
            'the pool must keep serving after a crash'
        );
    }

    /** Test 4: SIGHUP swaps the generation without dropping requests. */
    public function testReloadReplacesEveryWorkerWithoutLosingRequests(): void
    {
        $client = $this->startServer();

        $before = $this->waitForWorkers(2);

        // Requests already on the wire when the reload lands.
        $inFlight = [];
        for ($i = 1; $i <= 20; $i++) {
            $inFlight[] = $client->send(new Request('calculate', new CalculateRequest($i, 0)));
        }

        posix_kill($this->masterPid(), SIGHUP);

        $results = $client->all(...$inFlight);
        $this->assertCount(20, $results, 'a reload must not drop work already accepted');

        // And the generation really was replaced, not just restarted.
        $deadline = microtime(true) + 10.0;
        do {
            $after = $this->workerPids();
            $swapped = count($after) >= count($before) && array_intersect($before, $after) === [];
            usleep(50_000);
        } while (!$swapped && microtime(true) < $deadline);

        $this->assertTrue($swapped, 'every original worker pid should be gone after SIGHUP');
        $this->assertSame(
            ['result' => 30],
            $client->call(new Request('calculate', new CalculateRequest(10, 20))),
            'the new generation must serve normally'
        );
    }

    /** Test 5: SIGTERM stops accepting, finishes accepted work, removes the socket. */
    public function testShutdownDeliversAcceptedWorkAndCleansUp(): void
    {
        $client = $this->startServer();

        $inFlight = [];
        for ($i = 1; $i <= 10; $i++) {
            $inFlight[] = $client->send(new Request('calculate', new CalculateRequest($i, $i)));
        }

        posix_kill($this->masterPid(), SIGTERM);

        // Everything already accepted is still answered - either with a real
        // response or, if the drain window closed first, an explicit error.
        // What must never happen is silence.
        $answered = 0;
        foreach ($inFlight as $pending) {
            try {
                $pending->await();
                $answered++;
            } catch (ServerErrorException) {
                $answered++; // told, not dropped
            }
        }

        $this->assertSame(10, $answered, 'shutdown must answer everything it accepted');

        $deadline = microtime(true) + 15.0;
        while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) {
            usleep(50_000);
        }

        $this->assertFalse(proc_get_status($this->process)['running'], 'server should have exited');
        $this->assertFileDoesNotExist($this->socketPath, 'the socket file must be removed');
        $this->assertSame([], $this->workerPids(), 'no worker should outlive the Master');
    }
}
