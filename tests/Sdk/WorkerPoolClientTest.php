<?php

declare(strict_types=1);

namespace App\Tests\Sdk;

use App\IPC\Socket;
use App\Protocol\Message;
use App\Protocol\MessageType;
use App\Sdk\ConnectionFailedException;
use App\Sdk\RequestTimedOutException;
use App\Sdk\WorkerPoolClient;
use PHPUnit\Framework\TestCase;

final class WorkerPoolClientTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = '/tmp/worker-pool-client-test-' . getmypid() . '.sock';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    /** Forks a one-shot server: accept one connection, hand its first request to $respond, exit. */
    private function forkServer(\Closure $respond): int
    {
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'fork failed');

        if ($pid === 0) {
            $listener = stream_socket_server('unix://' . $this->path);
            $connection = stream_socket_accept($listener, 10);
            $socket = new Socket($connection);

            $respond($socket, $socket->read()[0]);

            exit(0);
        }

        $this->waitUntilListening();

        return $pid;
    }

    private function waitUntilListening(): void
    {
        // Not a connect-and-disconnect probe on purpose: stream_socket_accept()
        // below only ever accepts once, so a probe connection would itself get
        // accepted (and immediately closed), starving the real client's
        // connection. bind() creates the socket file synchronously, and once
        // listen() is active the OS queues connections even before accept() is
        // called, so waiting for the file to exist is enough - and it never
        // touches the accept queue.
        for ($i = 0; $i < 50; $i++) {
            if (file_exists($this->path)) {
                return;
            }

            usleep(20_000);
        }

        $this->fail('Test server never started listening on ' . $this->path);
    }

    public function testCallSendsRequestAndReturnsDecodedResponsePayload(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension required');
        }

        $pid = $this->forkServer(function (Socket $socket, Message $request): void {
            $socket->write(new Message(MessageType::RESPONSE, $request->id, ['result' => 30]));
        });

        $client = new WorkerPoolClient($this->path);
        $response = $client->call('calculate', ['a' => 10, 'b' => 20]);

        $this->assertSame(['result' => 30], $response);

        pcntl_waitpid($pid, $status);
    }

    public function testCallSendsActionAndParamsInThePayload(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension required');
        }

        $pid = $this->forkServer(function (Socket $socket, Message $request): void {
            // Echo the request payload back so the parent can assert on it.
            $socket->write(new Message(MessageType::RESPONSE, $request->id, $request->payload));
        });

        $client = new WorkerPoolClient($this->path);
        $response = $client->call('calculate', ['a' => 10, 'b' => 20]);

        $this->assertSame([
            'action' => 'calculate',
            'params' => ['a' => 10, 'b' => 20],
        ], $response);

        pcntl_waitpid($pid, $status);
    }

    public function testConnectionFailureThrowsConnectionFailedException(): void
    {
        $client = new WorkerPoolClient('/tmp/worker-pool-client-test-nothing-listening-here.sock');

        $this->expectException(ConnectionFailedException::class);

        $client->call('calculate', ['a' => 1, 'b' => 2]);
    }

    public function testNoResponseThrowsRequestTimedOutException(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension required');
        }

        // Accepts the connection and reads the request, but never responds -
        // sleeps past the client's timeout so the connection stays open the
        // whole time (forkServer() exits right after this closure returns,
        // which would otherwise look like a dropped connection instead).
        $pid = $this->forkServer(function (Socket $socket, Message $request): void {
            sleep(1);
        });

        $client = new WorkerPoolClient($this->path, timeoutSeconds: 0.3);

        try {
            $this->expectException(RequestTimedOutException::class);

            $client->call('calculate', ['a' => 1, 'b' => 2]);
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }
}
