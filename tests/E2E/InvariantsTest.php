<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Contract\Calculate\CalculateRequest;
use App\IPC\Socket;
use App\Protocol\Message;
use App\Protocol\MessageType;
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
 *   7. nothing a client sends can end a worker
 *   8. every accepted request is accounted for in the metrics
 */
final class InvariantsTest extends TestCase
{
    /** @var resource|null */
    private mixed $process = null;

    /** @var array<int, resource> the Master's stdout/stderr - stdout is where its SIGUSR1 metrics dump lands */
    private array $pipes = [];

    private string $socketPath = '';

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            // SIGTERM before SIGKILL, even in teardown: killing the Master
            // outright orphans every worker it forked, and an orphan whose
            // exit nobody waits for is a zombie until the container dies.
            // Shutting it down properly makes it reap its own children.
            if (proc_get_status($this->process)['running']) {
                proc_terminate($this->process, SIGTERM);

                $deadline = microtime(true) + 5.0;
                while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) {
                    usleep(20_000);
                }

                if (proc_get_status($this->process)['running']) {
                    proc_terminate($this->process, SIGKILL);
                }
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
        $this->pipes = $pipes;

        // Non-blocking, because the metrics dump is read by polling: a
        // blocking read on a Master that hasn't answered SIGUSR1 yet would
        // hang the test instead of failing it.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

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
     * Invariant 7: a client cannot end a worker. Both ends of the pool speak
     * one wire format, so a client's frame type used to be forwarded to a
     * worker verbatim - and a SHUTDOWN frame made the worker that received
     * it exit exactly as if the Master had retired it. One frame per worker,
     * a reap and a fork each time, from a peer whose only privilege is
     * being able to ask for work.
     */
    public function testAClientCannotShutDownAWorkerWithItsOwnFrames(): void
    {
        $client = $this->startServer();
        $before = $this->workerPids();
        $this->assertNotSame([], $before);

        // Raw connection rather than the SDK: the SDK only ever sends
        // REQUEST, and the point here is a frame it would never produce.
        $raw = stream_socket_client('unix://' . $this->socketPath, $errno, $error, 5.0);
        $this->assertIsResource($raw, (string) $error);

        $rawSocket = new Socket($raw);
        $rawSocket->write(new Message(MessageType::SHUTDOWN, 'kill-a-worker'));

        // Long enough for the frame to be read, dispatched (if it were
        // going to be) and for a worker's death to be reaped and replaced.
        usleep(500_000);

        $this->assertSame($before, $this->workerPids(), 'no worker may die because a client asked it to');

        // And the offender is dropped, the same way a malformed frame is:
        // a peer that isn't speaking the protocol it claims to has nothing
        // left to say that can be trusted.
        $this->assertSame(
            '',
            (string) fread($raw, 1024),
            'a protocol violation must cost the connection'
        );
        $this->assertTrue(feof($raw), 'the Master must have closed it');
        fclose($raw);

        // Everyone else is entirely unaffected.
        $this->assertSame(
            ['result' => 30],
            $client->call(new Request('calculate', new CalculateRequest(10, 20)))
        );
    }

    /**
     * Invariant 8: every request the Master accepted is in exactly one
     * bucket - answered, failed, timed out, rejected, or still in flight -
     * so the five counters must add up to requestsTotal in the real
     * Master's own snapshot, not just in a unit test's arithmetic
     * (MetricsCollectorTest covers that half).
     *
     * They are counted in three components that have no view of each other
     * (RequestMetrics, PendingRequestRegistry, RequestQueue), which is
     * exactly why a request can quietly fall out of all of them: whichever
     * of Master's paths forgets to record one, this is where it shows.
     * Driven through a mix of outcomes on purpose - plain answers, a worker
     * killed mid-flight, clients that vanish - since a partition of only
     * successes proves nothing.
     */
    public function testTheMetricsAccountForEveryRequestTheMasterAccepted(): void
    {
        $client = $this->startServer();

        // Answered.
        for ($i = 0; $i < 20; $i++) {
            $client->call(new Request('calculate', new CalculateRequest($i, $i)));
        }

        // Failed: an action the handler rejects, which comes back as an
        // ERROR frame - the deterministic half of the failure mix.
        for ($i = 0; $i < 5; $i++) {
            try {
                $client->call(new Request('no-such-action', new CalculateRequest($i, $i)));
                $this->fail('an unknown action must not be answered as a success');
            } catch (ServerErrorException $e) {
                $this->assertSame('unknown_action', $e->error);
            }
        }

        // Failed the harder way: a burst in flight, then every worker killed
        // under it. Whether any request is still unanswered when the signal
        // lands is a race, and deliberately not asserted - either outcome is
        // a bucket, which is the whole point.
        $inFlight = [];
        for ($i = 0; $i < 10; $i++) {
            $inFlight[] = $client->send(new Request('calculate', new CalculateRequest($i, 1)));
        }

        foreach ($this->workerPids() as $pid) {
            posix_kill($pid, SIGKILL);
        }

        foreach ($inFlight as $handle) {
            try {
                $handle->await();
            } catch (ServerErrorException) {
                // worker_crashed is one of the outcomes being counted.
            }
        }

        // Clients that disconnect mid-request - same again: whatever the
        // race decides, the request must land in exactly one bucket.
        for ($i = 0; $i < 10; $i++) {
            $rude = new WorkerPoolClient($this->socketPath, timeoutSeconds: 5.0);
            $rude->send(new Request('calculate', new CalculateRequest($i, $i)));
            $rude->close();
        }

        // And a couple left genuinely in flight while the snapshot is taken.
        $client->send(new Request('calculate', new CalculateRequest(1, 1)));
        $client->send(new Request('calculate', new CalculateRequest(2, 2)));

        $metrics = $this->dumpMetrics();

        $accountedFor = $metrics['Completed']
            + $metrics['Failed']
            + $metrics['Timeout']
            + $metrics['Rejected']
            + $metrics['In flight'];

        $this->assertGreaterThan(0, $metrics['Total'], 'the workload must actually have reached the Master');
        $this->assertGreaterThan(0, $metrics['Failed'], 'the failure half of the mix must have happened');
        $this->assertSame(
            $metrics['Total'],
            $accountedFor,
            sprintf('requests unaccounted for in %s', json_encode($metrics))
        );
    }

    /**
     * Asks the real Master for its metrics the way an operator would - SIGUSR1
     * - and parses the block it prints. Reading the numbers back out of
     * format() rather than from an API also means the dump an operator
     * actually looks at is what gets asserted.
     *
     * @return array<string, int>
     */
    private function dumpMetrics(): array
    {
        posix_kill($this->masterPid(), SIGUSR1);

        $output = '';
        $deadline = microtime(true) + 10.0;

        while (microtime(true) < $deadline) {
            $output .= (string) fread($this->pipes[1], 65536);

            if (preg_match('/In flight: (\d+)/', $output) === 1) {
                break;
            }

            usleep(50_000);
        }

        // The Requests block only: "Total" appears under Workers too, and
        // reading the pool's size as the request count is exactly the kind
        // of wrong number this test exists to catch.
        $this->assertSame(
            1,
            preg_match('/Requests:\n(.+?)\n\n/s', $output, $block),
            sprintf('the metrics dump should carry a Requests block, got: %s', $output)
        );

        $metrics = [];

        foreach (['Total', 'Completed', 'Failed', 'Timeout', 'Rejected', 'In flight'] as $label) {
            $this->assertSame(
                1,
                preg_match('/^  ' . preg_quote($label, '/') . ': (\d+)$/m', $block[1], $matches),
                sprintf('the Requests block should report "%s", got: %s', $label, $block[1])
            );

            $metrics[$label] = (int) $matches[1];
        }

        return $metrics;
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
