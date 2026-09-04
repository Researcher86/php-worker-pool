<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Contract\Calculate\CalculateRequest;
use App\Protocol\Request;
use App\Sdk\ConnectionFailedException;
use App\Sdk\WorkerPoolClient;
use PHPUnit\Framework\TestCase;

/**
 * Boots the real bin/server.php as a separate OS process and talks to it
 * through the real SDK over a real Unix socket. This is the one test that
 * exercises the wiring nothing else can: Master::run()'s closure plumbing,
 * UnixSocketServer accepting an external process, actual forked workers,
 * and the SIGTERM graceful-shutdown path including socket file removal.
 */
final class MasterEndToEndTest extends TestCase
{
    public function testRealServerAnswersARequestAndShutsDownGracefully(): void
    {
        $socketPath = sys_get_temp_dir() . '/pwp-e2e-' . getmypid() . '.sock';

        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../../bin/server.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['WORKER_POOL_SOCKET' => $socketPath]
        );
        $this->assertIsResource($process);

        try {
            $client = new WorkerPoolClient($socketPath, timeoutSeconds: 5.0);

            $response = $this->callOnceServerIsUp($client);
            $this->assertSame(['result' => 30], $response);

            // The same call with the contract DTO instead of an array -
            // the exact class the real server hydrates on its side, so this
            // covers the typed path end to end.
            $this->assertSame(
                ['result' => 7],
                $client->call(new Request('calculate', new CalculateRequest(3, 4)))
            );

            proc_terminate($process, SIGTERM);

            $this->assertTrue($this->waitForExit($process, 15.0), 'server did not exit after SIGTERM');
            $this->assertFileDoesNotExist($socketPath, 'graceful shutdown must remove the socket file');
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, SIGKILL);
            }

            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            proc_close($process);
            @unlink($socketPath);
        }
    }

    /**
     * The server binds its socket asynchronously to this test - retry the
     * first call until it's accepting, bounded so a server that never comes
     * up fails the test instead of hanging it.
     *
     * @return array<string, mixed>
     */
    private function callOnceServerIsUp(WorkerPoolClient $client): array
    {
        $deadline = microtime(true) + 10.0;

        while (true) {
            try {
                return $client->call(new Request('calculate', ['a' => 10, 'b' => 20]));
            } catch (ConnectionFailedException $e) {
                if (microtime(true) >= $deadline) {
                    throw $e;
                }

                usleep(50_000);
            }
        }
    }

    /** @param resource $process */
    private function waitForExit(mixed $process, float $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            if (!proc_get_status($process)['running']) {
                return true;
            }

            usleep(50_000);
        }

        return false;
    }
}
