<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Contract\Calculate\CalculateRequest;
use App\Protocol\Request;
use App\Sdk\ConnectionFailedException;
use App\Sdk\PendingResponse;
use App\Sdk\ServerErrorException;
use App\Sdk\WorkerPoolClient;
use PHPUnit\Framework\TestCase;

/**
 * Properties that must hold no matter what happens, asserted against a real
 * Master under deliberate abuse - as opposed to the component tests, which
 * check one mechanism in isolation with the timing chosen by the test.
 *
 * The invariants:
 *   1. one worker never runs two requests at once
 *   2. every accepted request reaches exactly one terminal outcome
 *   3. a DEAD worker never receives work          (unit: ReapRaceTest)
 *   4. a DRAINING worker never receives new work  (unit: WorkerPoolTest)
 *   5. worker replacement never exceeds maxWorkers
 *   6. after shutdown: no workers, no socket file
 */
final class InvariantsTest extends TestCase
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

    /** Boots bin/server.php with the given environment and waits until it answers. */
    private function startServer(string $handlerScript = 'server.php'): WorkerPoolClient
    {
        $this->socketPath = sys_get_temp_dir() . '/pwp-inv-' . getmypid() . '-' . uniqid() . '.sock';

        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../../bin/' . $handlerScript],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['WORKER_POOL_SOCKET' => $this->socketPath]
        );

        $this->assertIsResource($process);
        $this->process = $process;

        $client = new WorkerPoolClient($this->socketPath, timeoutSeconds: 15.0);
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
        return proc_get_status($this->process)['pid'];
    }

    /** @return list<int> */
    private function workerPids(): array
    {
        $output = shell_exec('pgrep -P ' . $this->masterPid() . ' 2>/dev/null') ?? '';

        return array_values(array_filter(array_map('intval', explode("\n", trim($output)))));
    }

    /**
     * Invariants 1 and 2, under a burst: every request gets exactly one
     * answer, that answer is the RIGHT one, and no worker mixed two
     * requests up (which a wrong answer is precisely how you'd notice -
     * every request carries a distinct operand pair).
     */
    public function testEveryRequestGetsExactlyOneCorrectAnswer(): void
    {
        $client = $this->startServer();

        /** @var array<int, PendingResponse> $pending */
        $pending = [];
        for ($i = 1; $i <= 200; $i++) {
            $pending[$i] = $client->send(new Request('calculate', new CalculateRequest($i, $i * 2)));
        }

        $seen = [];
        foreach ($pending as $i => $handle) {
            $result = $handle->await();

            $this->assertSame(
                ['result' => $i * 3],
                $result,
                "request #$i got an answer belonging to a different request - workers are mixing state"
            );

            $this->assertArrayNotHasKey($i, $seen, "request #$i was answered twice");
            $seen[$i] = true;
        }

        $this->assertCount(200, $seen);
    }

    /**
     * Invariant 2 under failure: a request whose worker is killed still
     * reaches a terminal outcome - an error - rather than hanging until the
     * client's own timeout.
     */
    public function testARequestWhoseWorkerDiesStillTerminates(): void
    {
        $client = $this->startServer();

        $this->assertNotSame([], $this->workerPids());

        // Kill every worker there is, so whichever one is about to take this
        // request dies with it.
        $handle = $client->send(new Request('calculate', new CalculateRequest(1, 1)));

        foreach ($this->workerPids() as $pid) {
            posix_kill($pid, SIGKILL);
        }

        $outcome = null;
        try {
            $handle->await();
            $outcome = 'answered';
        } catch (ServerErrorException $e) {
            $outcome = $e->error;
        }

        $this->assertContains(
            $outcome,
            ['answered', 'worker_crashed', 'request_timeout'],
            'a request must always end somewhere - answered or explicitly failed'
        );
    }

    /**
     * Invariant 5: crashes are replaced, but never past the ceiling. Kill
     * workers repeatedly and the pool must converge back to a sane size
     * rather than accumulating replacements.
     */
    public function testRepeatedCrashesNeverGrowThePoolPastItsCeiling(): void
    {
        $client = $this->startServer();

        for ($round = 0; $round < 5; $round++) {
            foreach ($this->workerPids() as $pid) {
                posix_kill($pid, SIGKILL);
            }

            usleep(200_000);
            $client->call(new Request('calculate', new CalculateRequest(1, 1)));
        }

        // Settle, then check: bin/server.php's Master allows at most 16.
        usleep(500_000);
        $pids = $this->workerPids();

        $this->assertLessThanOrEqual(16, count($pids), 'replacements must respect maxWorkers');
        $this->assertGreaterThanOrEqual(1, count($pids), 'the pool must recover to a serving size');
        $this->assertSame(
            ['result' => 30],
            $client->call(new Request('calculate', new CalculateRequest(10, 20)))
        );
    }

    /**
     * Invariant 6: nothing outlives the Master - no orphaned workers, no
     * socket file left for the next boot to trip over.
     */
    public function testNothingSurvivesShutdown(): void
    {
        $client = $this->startServer();
        $client->call(new Request('calculate', new CalculateRequest(1, 1)));

        $this->assertNotSame([], $this->workerPids());

        posix_kill($this->masterPid(), SIGTERM);

        $deadline = microtime(true) + 15.0;
        while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) {
            usleep(50_000);
        }

        $this->assertFalse(proc_get_status($this->process)['running'], 'the Master must exit');
        $this->assertSame([], $this->workerPids(), 'no worker may outlive the Master');
        $this->assertFileDoesNotExist($this->socketPath, 'the socket file must be removed');
    }

    /**
     * The review's noisy-neighbour question, answered as a measurement
     * rather than a promise: a client that disconnects mid-request must not
     * leave anything behind. 60 connect-send-vanish clients, then the pool
     * must still be healthy - if pending entries leaked, the queue and the
     * registry would still be holding them.
     */
    public function testClientsThatVanishMidRequestLeaveNothingBehind(): void
    {
        $client = $this->startServer();

        for ($i = 0; $i < 60; $i++) {
            $rude = new WorkerPoolClient($this->socketPath, timeoutSeconds: 5.0);
            $rude->send(new Request('calculate', new CalculateRequest($i, $i)));
            $rude->close(); // gone before the answer could arrive
        }

        // The Master must still be entirely normal afterwards.
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame(
                ['result' => 30],
                $client->call(new Request('calculate', new CalculateRequest(10, 20))),
                'the Master must stay healthy after clients disconnect mid-request'
            );
        }

        $this->assertNotSame([], $this->workerPids(), 'workers must not have been lost to vanishing clients');
    }

    /**
     * Everything at once, which is the only way combinations get tested:
     * a burst in flight while a worker is killed, a reload is signalled,
     * clients vanish, and recycling churns workers underneath - all while
     * the queue has a backlog.
     *
     * What is asserted is not "nothing went wrong" but the invariants:
     * every request that was accepted ends somewhere, no answer is
     * fabricated or misrouted, the pool never exceeds its ceiling, the
     * Master survives, and the whole thing settles back to a working state.
     */
    public function testTheWholeLifecycleUnderSimultaneousChaos(): void
    {
        $client = $this->startServer('chaos-server.php');

        /** @var array<int, PendingResponse> $pending */
        $pending = [];
        for ($i = 1; $i <= 120; $i++) {
            $pending[$i] = $client->send(new Request('calculate', new CalculateRequest($i, $i * 2)));

            // Mid-burst, do everything that can disturb a pool.
            if ($i === 20) {
                $workers = $this->workerPids();
                if ($workers !== []) {
                    posix_kill($workers[0], SIGKILL);          // crash + replacement
                }
            }

            if ($i === 40) {
                posix_kill($this->masterPid(), SIGHUP);         // whole generation swapped
            }

            if ($i === 60) {
                for ($r = 0; $r < 10; $r++) {                   // clients that vanish mid-request
                    $rude = new WorkerPoolClient($this->socketPath, timeoutSeconds: 5.0);
                    $rude->send(new Request('calculate', new CalculateRequest($r, $r)));
                    $rude->close();
                }
            }

            if ($i === 80) {
                $workers = $this->workerPids();
                if ($workers !== []) {
                    posix_kill($workers[array_key_last($workers)], SIGKILL);
                }
            }
        }

        // INVARIANT: every accepted request reaches exactly one terminal
        // outcome, and a successful one is never the wrong answer.
        $answered = 0;
        $failed = 0;

        foreach ($pending as $i => $handle) {
            try {
                $this->assertSame(
                    ['result' => $i * 3],
                    $handle->await(),
                    "request #$i came back with another request's answer"
                );
                $answered++;
            } catch (ServerErrorException) {
                $failed++; // worker_crashed etc - a terminal outcome, which is the invariant
            }
        }

        $this->assertSame(120, $answered + $failed, 'every request must terminate');
        $this->assertGreaterThan(100, $answered, 'chaos should cost a few requests at most, not most of them');

        // INVARIANT: the pool respects its ceiling and is alive.
        usleep(500_000);
        $this->assertLessThanOrEqual(16, count($this->workerPids()), 'maxWorkers must hold through all of that');

        // INVARIANT: it settles back to a working state.
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(
                ['result' => 30],
                $client->call(new Request('calculate', new CalculateRequest(10, 20))),
                'the Master must be fully functional once the dust settles'
            );
        }

        // INVARIANT: and it still shuts down cleanly afterwards.
        posix_kill($this->masterPid(), SIGTERM);

        $deadline = microtime(true) + 15.0;
        while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) {
            usleep(50_000);
        }

        $this->assertFalse(proc_get_status($this->process)['running']);
        $this->assertSame([], $this->workerPids(), 'no worker may outlive the Master, even after chaos');
        $this->assertFileDoesNotExist($this->socketPath);
    }
}
