<?php

declare(strict_types=1);

namespace App\Tests\Client;

use App\Client\ClientConnection;
use App\Client\ClientRegistry;
use App\Client\PendingRequestRegistry;
use App\Dispatcher\Dispatcher;
use App\EventLoop\EventLoop;
use App\IPC\Socket;
use App\Protocol\Message;
use App\Protocol\MessageType;
use App\Queue\RequestQueue;
use App\Tests\Worker\FakeWorkerLauncher;
use App\Worker\WorkerPool;
use PHPUnit\Framework\TestCase;

final class ClientRegistryTest extends TestCase
{
    /** @return array{0: resource, 1: resource} */
    private function pair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);

        return $pair;
    }

    public function testAcceptedClientIsTrackedAndRegisteredWithTheLoop(): void
    {
        $loop = new EventLoop();
        $registry = new ClientRegistry($loop, static function (): void {
        });

        [$serverEnd, $clientEnd] = $this->pair();
        $registry->accept($serverEnd);

        $this->assertSame(1, $registry->count());
        $this->assertTrue($loop->hasReadable());

        fclose($clientEnd);
    }

    public function testDecodedRequestInvokesCallbackWithClientAndMessage(): void
    {
        $loop = new EventLoop();
        $received = null;

        $registry = new ClientRegistry(
            $loop,
            function (ClientConnection $client, Message $message) use (&$received): void {
                $received = [$client, $message];
            }
        );

        [$serverEnd, $clientEnd] = $this->pair();
        $registry->accept($serverEnd);

        (new Socket($clientEnd))->write(new Message(MessageType::REQUEST, 'req-1', ['x' => 1]));
        $loop->tick();

        $this->assertNotNull($received);
        [$client, $message] = $received;
        $this->assertInstanceOf(ClientConnection::class, $client);
        $this->assertSame('req-1', $message->id);
        $this->assertSame(['x' => 1], $message->payload);

        fclose($clientEnd);
    }

    public function testDisconnectedClientIsRemovedWithoutCrashing(): void
    {
        $loop = new EventLoop();
        $registry = new ClientRegistry($loop, static function (): void {
        });

        [$serverEnd, $clientEnd] = $this->pair();
        $registry->accept($serverEnd);

        fclose($clientEnd);
        $loop->tick();

        $this->assertSame(0, $registry->count());
        $this->assertFalse($loop->hasReadable());
    }

    /**
     * PHASES.md Phase 11's PendingRequestRegistry needs to know when a client
     * it's tracking requests for is gone, so it can drop those entries
     * instead of waiting out their timeout for nothing - onDisconnect is
     * the hook Master wires that through. Covers both ways a client gets
     * dropped (clean disconnect and a malformed frame).
     */
    public function testOnDisconnectFiresWithTheClientOnCleanDisconnect(): void
    {
        $loop = new EventLoop();
        $disconnected = null;

        $registry = new ClientRegistry(
            $loop,
            static function (): void {
            },
            function (ClientConnection $client) use (&$disconnected): void {
                $disconnected = $client;
            }
        );

        [$serverEnd, $clientEnd] = $this->pair();
        $registry->accept($serverEnd);

        fclose($clientEnd);
        $loop->tick();

        $this->assertInstanceOf(ClientConnection::class, $disconnected);
    }

    public function testOnDisconnectFiresOnAMalformedFrameToo(): void
    {
        $loop = new EventLoop();
        $disconnected = null;

        $registry = new ClientRegistry(
            $loop,
            static function (): void {
            },
            function (ClientConnection $client) use (&$disconnected): void {
                $disconnected = $client;
            }
        );

        [$serverEnd, $clientEnd] = $this->pair();
        $registry->accept($serverEnd);

        fwrite($clientEnd, pack('N', 5) . 'not{}');
        $loop->tick();

        $this->assertInstanceOf(ClientConnection::class, $disconnected);

        fclose($clientEnd);
    }

    public function testMalformedRequestDisconnectsClientWithoutCrashing(): void
    {
        $loop = new EventLoop();
        $registry = new ClientRegistry($loop, static function (): void {
        });

        [$serverEnd, $clientEnd] = $this->pair();
        $registry->accept($serverEnd);

        // A frame claiming a 5-byte payload that is actually invalid JSON.
        fwrite($clientEnd, pack('N', 5) . 'not{}');
        $loop->tick();

        $this->assertSame(0, $registry->count());
        $this->assertFalse($loop->hasReadable());

        fclose($clientEnd);
    }

    public function testTracksMultipleClientsIndependently(): void
    {
        $loop = new EventLoop();
        $registry = new ClientRegistry($loop, static function (): void {
        });

        [$serverA, $clientA] = $this->pair();
        [$serverB, $clientB] = $this->pair();

        $registry->accept($serverA);
        $registry->accept($serverB);

        $this->assertSame(2, $registry->count());

        fclose($clientA);
        fclose($clientB);
    }

    /**
     * A client can also become undeliverable without ever disconnecting:
     * it stops reading, its unsent responses pass ClientConnection's
     * write-buffer cap, and the connection is given up on. Until this
     * sweep existed, nothing removed it - it kept its read handler and
     * went on submitting requests that took workers and queue slots to
     * produce answers that could never be delivered, for as long as it
     * cared to keep sending.
     */
    public function testAClientThatCanNoLongerBeWrittenToIsSweptOutOfTheRegistry(): void
    {
        $loop = new EventLoop();
        $seen = null;
        $disconnected = null;

        $registry = new ClientRegistry(
            $loop,
            function (ClientConnection $client) use (&$seen): void {
                $seen = $client;
            },
            function (ClientConnection $client) use (&$disconnected): void {
                $disconnected = $client;
            }
        );

        [$serverEnd, $clientEnd] = $this->pair();
        stream_set_blocking($serverEnd, false);
        $registry->accept($serverEnd);

        // One request, purely to get hold of the registry's own
        // ClientConnection for this peer.
        (new Socket($clientEnd))->write(new Message(MessageType::REQUEST, 'req-1'));
        $loop->tick();
        $this->assertInstanceOf(ClientConnection::class, $seen);

        // A response far past the 4 MB cap, with a peer that never reads it.
        $seen->write(new Message(MessageType::RESPONSE, 'req-1', ['blob' => str_repeat('x', 5 * 1024 * 1024)]));
        $this->assertTrue($seen->isBroken());

        $this->assertSame(1, $registry->removeBroken());
        $this->assertSame(0, $registry->count());
        $this->assertFalse($loop->hasReadable(), 'its read handler must go with it');
        $this->assertSame($seen, $disconnected, 'onDisconnect is what releases its pending requests');

        // Idempotent: nothing left to sweep on the next tick.
        $this->assertSame(0, $registry->removeBroken());

        fclose($clientEnd);
    }

    /**
     * The same client, within the single read that broke it. removeBroken()
     * runs once per Master tick, so the rest of a batch decoded in the same
     * read would otherwise still be dispatched - work taken on for a client
     * that can no longer be answered.
     */
    public function testTheRestOfABatchIsDroppedOnceTheClientBreaksMidRead(): void
    {
        $loop = new EventLoop();
        $handled = [];

        $registry = new ClientRegistry(
            $loop,
            function (ClientConnection $client, Message $request) use (&$handled): void {
                $handled[] = $request->id;

                // Stands in for what the Master does with a request: write
                // something back. This one is over the cap, so the client
                // breaks while its own batch is still being iterated.
                if ($request->id === 'req-1') {
                    $client->write(new Message(MessageType::RESPONSE, $request->id, ['blob' => str_repeat('x', 5 * 1024 * 1024)]));
                }
            }
        );

        [$serverEnd, $clientEnd] = $this->pair();
        stream_set_blocking($serverEnd, false);
        $registry->accept($serverEnd);

        $clientSocket = new Socket($clientEnd);
        $clientSocket->write(new Message(MessageType::REQUEST, 'req-1'));
        $clientSocket->write(new Message(MessageType::REQUEST, 'req-2'));
        $clientSocket->write(new Message(MessageType::REQUEST, 'req-3'));

        $loop->tick();

        $this->assertSame(['req-1'], $handled, 'nothing after the break may be taken on');

        fclose($clientEnd);
    }

    /**
     * PHASES.md Phase 18's Definition of Done: one connection can have
     * multiple pending requests at once. This isn't new machinery - the
     * handler already reads and decodes everything readAvailable() returns
     * in one pass, invoking onRequest once per message - this just proves
     * three requests sent back to back on the same connection, before any
     * response, all get delivered rather than only the first one.
     */
    public function testMultipleRequestsOnOneConnectionAllReachOnRequest(): void
    {
        $loop = new EventLoop();
        $received = [];

        $registry = new ClientRegistry(
            $loop,
            function (ClientConnection $client, Message $message) use (&$received): void {
                $received[] = $message->id;
            }
        );

        [$serverEnd, $clientEnd] = $this->pair();
        $registry->accept($serverEnd);

        $clientSocket = new Socket($clientEnd);
        $clientSocket->write(new Message(MessageType::REQUEST, 'req-1'));
        $clientSocket->write(new Message(MessageType::REQUEST, 'req-2'));
        $clientSocket->write(new Message(MessageType::REQUEST, 'req-3'));

        $loop->tick();

        $this->assertSame(['req-1', 'req-2', 'req-3'], $received);

        fclose($clientEnd);
    }

    /**
     * The other half of PHASES.md Phase 18's Definition of Done: responses to
     * concurrent requests on one connection "may arrive" out of order
     * (the plan's own example: #2, #1, #3), and each must still reach the
     * client under its own original id - reusing the full Master-style
     * wiring (Dispatcher + PendingRequestRegistry), just without a real
     * fork or socket server, to prove the id-based routing genuinely
     * doesn't assume in-order completion.
     */
    public function testConcurrentRequestsGetRoutedBackCorrectlyEvenWhenAnsweredOutOfOrder(): void
    {
        $launcher = new FakeWorkerLauncher();
        $pool = new WorkerPool(3, $launcher);
        $loop = new EventLoop();
        $pendingRequests = new PendingRequestRegistry();

        $dispatcher = new Dispatcher(new RequestQueue(), $pool, $loop, function (Message $response) use ($pendingRequests): void {
            $pending = $pendingRequests->resolve($response->id);

            if ($pending !== null) {
                $pending->client->write(new Message($response->type, $pending->originalId, $response->payload));
            }
        });

        $clients = new ClientRegistry($loop, function (ClientConnection $client, Message $request) use ($dispatcher, $pendingRequests): void {
            $dispatchId = $pendingRequests->register($client, $request->id, 30.0);
            $dispatcher->dispatch(new Message($request->type, $dispatchId, $request->payload));
        });

        [$serverEnd, $clientEnd] = $this->pair();
        $clients->accept($serverEnd);

        $clientSocket = new Socket($clientEnd);
        $clientSocket->write(new Message(MessageType::REQUEST, 'task-1', ['n' => 1]));
        $clientSocket->write(new Message(MessageType::REQUEST, 'task-2', ['n' => 2]));
        $clientSocket->write(new Message(MessageType::REQUEST, 'task-3', ['n' => 3]));

        $loop->tick(); // accept the connection, decode and dispatch all three

        $this->assertSame(3, $pendingRequests->count());

        // Answer out of order (2, 3, 1) - each fake worker was given the
        // Master-assigned dispatch id ('req-N', in dispatch order), not the
        // client's original one.
        $workerEnds = $launcher->workerEnds();
        $workerEnds[1]->write(new Message(MessageType::RESPONSE, 'req-2', ['n' => 2]));
        $workerEnds[2]->write(new Message(MessageType::RESPONSE, 'req-3', ['n' => 3]));
        $workerEnds[0]->write(new Message(MessageType::RESPONSE, 'req-1', ['n' => 1]));

        // All three fake worker sockets are already readable at once here,
        // so one tick() normally drains all of them - but bound every call
        // in this loop so a timing fluke can never hang the test instead of
        // just failing the assertion below.
        $responses = [];
        for ($i = 0; $i < 10 && count($responses) < 3; $i++) {
            $loop->tick(0.2);
            $responses = array_merge($responses, $clientSocket->readAvailable(0.2));
        }

        $byId = [];
        foreach ($responses as $response) {
            $byId[$response->id] = $response->payload;
        }

        $this->assertSame([
            'task-1' => ['n' => 1],
            'task-2' => ['n' => 2],
            'task-3' => ['n' => 3],
        ], $byId);

        fclose($clientEnd);
    }
}
