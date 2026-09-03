<?php

declare(strict_types=1);

namespace App\Tests\Client;

use App\Client\ClientConnection;
use App\Client\ClientRegistry;
use App\EventLoop\EventLoop;
use App\IPC\Socket;
use App\Protocol\Message;
use App\Protocol\MessageType;
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
}
