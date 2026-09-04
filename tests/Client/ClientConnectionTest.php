<?php

declare(strict_types=1);

namespace App\Tests\Client;

use App\Client\ClientConnection;
use App\EventLoop\EventLoop;
use App\IPC\Socket;
use App\Protocol\Message;
use App\Protocol\MessageType;
use PHPUnit\Framework\TestCase;

final class ClientConnectionTest extends TestCase
{
    /** @return array{0: resource, 1: resource} */
    private function pair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);

        return $pair;
    }

    /**
     * The single-threaded Master must never block on one slow client while
     * every other client and worker waits: a response bigger than the kernel
     * send buffer is buffered and returned from immediately, then flushed
     * incrementally by the same EventLoop ticks that drive all other I/O,
     * as the client actually drains it.
     */
    public function testWriteBiggerThanTheKernelBufferReturnsImmediatelyAndFlushesViaTheLoop(): void
    {
        $loop = new EventLoop();
        [$serverEnd, $clientEnd] = $this->pair();
        stream_set_blocking($serverEnd, false);

        $connection = new ClientConnection(new Socket($serverEnd), $loop);
        $blob = str_repeat('x', 700_000);

        $start = microtime(true);
        $connection->write(new Message(MessageType::RESPONSE, 'req-1', ['blob' => $blob]));
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(1.0, $elapsed, 'write() must buffer and return, never block until the peer drains');
        $this->assertTrue($connection->hasPendingWrites(), 'the part past the kernel buffer should still be queued');

        // Drain from the client side while ticking the loop - each tick
        // flushes however much room the drain just freed up.
        $reader = new Socket($clientEnd);
        $received = [];

        for ($i = 0; $i < 500 && $received === []; $i++) {
            $loop->tick(0.05);
            $received = [...$received, ...$reader->readAvailable(0.05)];
        }

        $this->assertCount(1, $received);
        $this->assertSame('req-1', $received[0]->id);
        $this->assertSame($blob, $received[0]->payload['blob']);
        $this->assertFalse($connection->hasPendingWrites());

        $connection->close();
        fclose($clientEnd);
    }

    /**
     * The write buffer is the outbound counterpart of the request queue's
     * maxSize (PLAN.md Phase 13): a stuck client that never reads must not
     * grow the Master's memory without bound. Past the cap the client is
     * declared dead and its buffer dropped - never retained.
     */
    public function testWritesToAStuckClientAreCappedInsteadOfGrowingForever(): void
    {
        $loop = new EventLoop();
        [$serverEnd, $clientEnd] = $this->pair();
        stream_set_blocking($serverEnd, false);

        $connection = new ClientConnection(new Socket($serverEnd), $loop);

        // Nothing ever reads $clientEnd - each ~1MB response accumulates
        // past the kernel buffer until the cap trips.
        $bigResponse = new Message(MessageType::RESPONSE, 'req-1', ['blob' => str_repeat('x', 1_000_000)]);

        for ($i = 0; $i < 6; $i++) {
            $connection->write($bigResponse);
        }

        $this->assertFalse(
            $connection->hasPendingWrites(),
            'past the cap the buffer must be dropped, not retained without bound'
        );

        $connection->close();
        fclose($clientEnd);
    }
}
