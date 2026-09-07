<?php

declare(strict_types=1);

namespace App\Tests\Sdk;

use App\IPC\Socket;
use App\Protocol\Message;
use App\Protocol\MessageType;
use App\Protocol\Request;
use App\Sdk\ConnectionFailedException;
use App\Sdk\RequestTimedOutException;
use App\Sdk\ServerErrorException;
use App\Sdk\WorkerPoolClient;
use Closure;
use LogicException;
use PHPUnit\Framework\TestCase;

final class WorkerPoolClientTest extends TestCase
{
    private string $path;

    /** @var list<int> every server this test forked, reaped in tearDown */
    private array $servers = [];

    protected function setUp(): void
    {
        $this->path = '/tmp/worker-pool-client-test-' . getmypid() . '.sock';
    }

    /**
     * Reaping here rather than at the end of each test: a test that ends via
     * expectException never reaches the line after the throwing call, so any
     * pcntl_waitpid() written there is dead code and the child is left
     * unreaped - a zombie for as long as the container lives.
     */
    protected function tearDown(): void
    {
        foreach ($this->servers as $pid) {
            if (posix_kill($pid, 0)) {
                posix_kill($pid, SIGKILL);
            }

            pcntl_waitpid($pid, $status);
        }

        $this->servers = [];

        @unlink($this->path);
    }

    /** Forks a one-shot server: accept one connection, hand its first request to $respond, exit. */
    private function forkServer(Closure $respond): int
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

        $this->servers[] = $pid;
        $this->waitUntilListening();

        return $pid;
    }

    /**
     * Forks a server that reads $count requests off ONE connection before
     * answering any of them, then hands them all to $respond at once - the
     * multiplexing case: nothing can be answered in arrival order by
     * accident, because nothing is answered until every request is in.
     *
     * @param Closure(Socket, list<Message>): void $respond
     */
    private function forkPipeliningServer(int $count, Closure $respond): int
    {
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'fork failed');

        if ($pid === 0) {
            $listener = stream_socket_server('unix://' . $this->path);
            $connection = stream_socket_accept($listener, 10);
            $socket = new Socket($connection);

            $requests = [];
            while (count($requests) < $count) {
                $requests = [...$requests, ...$socket->read()];
            }

            $respond($socket, $requests);

            exit(0);
        }

        $this->servers[] = $pid;
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
        $pid = $this->forkServer(function (Socket $socket, Message $request): void {
            $socket->write(new Message(MessageType::RESPONSE, $request->id, ['result' => 30]));
        });

        $client = new WorkerPoolClient($this->path);
        $response = $client->call(new Request('calculate', ['a' => 10, 'b' => 20]));

        $this->assertSame(['result' => 30], $response);

        pcntl_waitpid($pid, $status);
    }

    public function testCallSendsActionAndParamsInThePayload(): void
    {
        $pid = $this->forkServer(function (Socket $socket, Message $request): void {
            // Echo the request payload back so the parent can assert on it.
            $socket->write(new Message(MessageType::RESPONSE, $request->id, $request->payload));
        });

        $client = new WorkerPoolClient($this->path);
        $response = $client->call(new Request('calculate', ['a' => 10, 'b' => 20]));

        $this->assertSame([
            'action' => 'calculate',
            'params' => ['a' => 10, 'b' => 20],
        ], $response);

        pcntl_waitpid($pid, $status);
    }

    /**
     * An ERROR from the server (overloaded, timed out server-side, worker
     * crashed, ...) is a refusal, not a result - call() used to return its
     * payload as if the request had succeeded, making every caller
     * responsible for remembering to check for an 'error' key.
     */
    public function testServerErrorResponseThrowsInsteadOfReturningItsPayload(): void
    {
        $pid = $this->forkServer(function (Socket $socket, Message $request): void {
            $socket->write(new Message(MessageType::ERROR, $request->id, ['error' => 'server_overloaded']));
        });

        $client = new WorkerPoolClient($this->path);

        try {
            $client->call(new Request('calculate', ['a' => 1, 'b' => 2]));
            $this->fail('an ERROR response must throw, not be returned as a result');
        } catch (ServerErrorException $e) {
            $this->assertSame('server_overloaded', $e->error);
            $this->assertSame(['error' => 'server_overloaded'], $e->payload);
        } finally {
            pcntl_waitpid($pid, $status);
        }
    }

    /**
     * $params may be a request DTO instead of an array - its JSON-visible
     * state becomes the params payload, so a caller can keep its call sites
     * typed.
     *
     * Deliberately a DTO local to this test rather than one from
     * App\Contract: what's under test is the SDK turning ANY object into
     * params, and a transport-level test shouldn't reach up into the
     * application layer to prove it. Real callers in this codebase do share
     * the contract class - see bin/client.php - and MasterEndToEndTest
     * covers that path against the real server.
     */
    public function testCallAcceptsAnObjectAsParams(): void
    {
        $pid = $this->forkServer(function (Socket $socket, Message $request): void {
            // Echo the request payload back so the parent can assert on the
            // exact wire shape the object produced.
            $socket->write(new Message(MessageType::RESPONSE, $request->id, $request->payload));
        });

        $client = new WorkerPoolClient($this->path);
        $response = $client->call(new Request('calculate', new Operands(10, 20)));

        $this->assertSame([
            'action' => 'calculate',
            'params' => ['a' => 10, 'b' => 20],
        ], $response);

        pcntl_waitpid($pid, $status);
    }

    /**
     * PHASES.md Phase 18's client half: send() puts a request on the wire and
     * returns immediately, so several can be in flight at once, and all()
     * collects them. The forked server here reads all three BEFORE answering
     * any, which only works because the client didn't block on the first.
     */
    public function testSendPutsSeveralRequestsInFlightAndAllCollectsThem(): void
    {
        $pid = $this->forkPipeliningServer(3, function (Socket $socket, array $requests): void {
            // Answer in reverse, so arrival order can't match asking order.
            foreach (array_reverse($requests) as $i => $request) {
                $socket->write(new Message(MessageType::RESPONSE, $request->id, ['n' => $request->payload['params']['n']]));
            }
        });

        $client = new WorkerPoolClient($this->path);

        $first = $client->send(new Request('calculate', ['n' => 1]));
        $second = $client->send(new Request('calculate', ['n' => 2]));
        $third = $client->send(new Request('calculate', ['n' => 3]));

        // Ordered by the handles given, not by when each answer arrived.
        $this->assertSame(
            [['n' => 1], ['n' => 2], ['n' => 3]],
            $client->all($first, $second, $third)
        );

        pcntl_waitpid($pid, $status);
    }

    /**
     * The handle can also be awaited on its own, in whatever order suits the
     * caller - answers nobody has asked for yet are buffered until they are.
     */
    public function testHandlesCanBeAwaitedIndividuallyInAnyOrder(): void
    {
        $pid = $this->forkPipeliningServer(2, function (Socket $socket, array $requests): void {
            foreach ($requests as $request) {
                $socket->write(new Message(MessageType::RESPONSE, $request->id, ['n' => $request->payload['params']['n']]));
            }
        });

        $client = new WorkerPoolClient($this->path);

        $first = $client->send(new Request('calculate', ['n' => 1]));
        $second = $client->send(new Request('calculate', ['n' => 2]));

        // Second first: the answer to $first arrives during this wait and is
        // buffered rather than mistaken for this one's.
        $this->assertSame(['n' => 2], $second->await());
        $this->assertSame(['n' => 1], $first->await());

        pcntl_waitpid($pid, $status);
    }

    /** A handle is one-shot - collecting it twice is a caller bug, not a silent reblock. */
    public function testAwaitingTheSameHandleTwiceThrows(): void
    {
        $pid = $this->forkServer(function (Socket $socket, Message $request): void {
            $socket->write(new Message(MessageType::RESPONSE, $request->id, ['result' => 30]));
        });

        $client = new WorkerPoolClient($this->path);
        $pending = $client->send(new Request('calculate', ['a' => 10, 'b' => 20]));

        $this->assertSame(['result' => 30], $pending->await());

        $this->expectException(LogicException::class);

        $pending->await();

        pcntl_waitpid($pid, $status);
    }

    /**
     * One failed request among several must surface as its own error when
     * that handle is collected - not corrupt or swallow the others.
     */
    public function testAFailedRequestAmongSeveralThrowsOnlyForItsOwnHandle(): void
    {
        $pid = $this->forkPipeliningServer(2, function (Socket $socket, array $requests): void {
            $socket->write(new Message(MessageType::ERROR, $requests[0]->id, ['error' => 'unknown_action']));
            $socket->write(new Message(MessageType::RESPONSE, $requests[1]->id, ['ok' => true]));
        });

        $client = new WorkerPoolClient($this->path);

        $failing = $client->send(new Request('nope'));
        $working = $client->send(new Request('calculate', ['a' => 1, 'b' => 2]));

        try {
            $failing->await();
            $this->fail('the failed request must throw when collected');
        } catch (ServerErrorException $e) {
            $this->assertSame('unknown_action', $e->error);
        }

        $this->assertSame(['ok' => true], $working->await(), 'the other request must be unaffected');

        pcntl_waitpid($pid, $status);
    }

    public function testConnectionFailureThrowsConnectionFailedException(): void
    {
        $client = new WorkerPoolClient('/tmp/worker-pool-client-test-nothing-listening-here.sock');

        $this->expectException(ConnectionFailedException::class);

        $client->call(new Request('calculate', ['a' => 1, 'b' => 2]));
    }

    public function testNoResponseThrowsRequestTimedOutException(): void
    {
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

            $client->call(new Request('calculate', ['a' => 1, 'b' => 2]));
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }
}

/** A caller-side request DTO for the object-params test above. */
final readonly class Operands
{
    public function __construct(
        public int $a,
        public int $b,
    ) {
    }
}
