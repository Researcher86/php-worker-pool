<?php

declare(strict_types=1);

namespace App\Client;

use App\EventLoop\EventLoop;
use App\IPC\Socket;
use App\Protocol\Message;
use App\Protocol\MessageEncoder;

/**
 * Master-side handle for one connected client: its id, its socket, and its
 * outgoing write buffer.
 *
 * Writes are buffered, never blocking: the Master is single-threaded, so one
 * slow client blocked mid-write would stall every other client and worker
 * for the duration (Socket::write()'s bounded-blocking retry is fine for
 * workers and the SDK, but not here). write() sends what the kernel will
 * take right now and keeps the rest, registering with the EventLoop for a
 * writability event to send more - the same tick() that drives all other
 * I/O flushes it, with no separate machinery.
 *
 * The read-side buffering PLAN.md's Phase 10 asks for is already provided by
 * Socket (its MessageDecoder buffers partial reads) — this class doesn't
 * need its own copy of that.
 */
final class ClientConnection
{
    /**
     * Cap on unsent bytes one client may accumulate (its responses plus any
     * error frames). A stuck client that never reads would otherwise grow
     * its buffer without bound - the same OOM-by-slow-consumer problem the
     * request queue's maxSize guards against on the way in (PLAN.md Phase
     * 13), just on the way out. Past it the client is treated as dead.
     */
    private const int MAX_WRITE_BUFFER_BYTES = 4 * 1024 * 1024;

    private readonly int $id;

    private string $writeBuffer = '';

    // Whether our resource is currently registered with the loop for
    // writability - i.e. flush() left bytes behind and is waiting for the
    // kernel buffer to drain.
    private bool $awaitingWritability = false;

    // Set when this connection can no longer be written to correctly (peer
    // gone, or buffer cap exceeded). One-way: all further writes are
    // dropped. The read side is what ultimately removes the client from
    // ClientRegistry - this flag just stops us wasting effort until then.
    private bool $broken = false;

    public function __construct(
        private readonly Socket $socket,
        // Null means "no loop to wait on" (only unit tests do this): flush()
        // still makes one send attempt per write(), it just can't retry on
        // writability - fine for test-sized messages, which fit the kernel
        // buffer in one attempt.
        private readonly ?EventLoop $loop = null,
        private readonly MessageEncoder $encoder = new MessageEncoder(),
    ) {
        // No pid to key by like WorkerProcess has, but a stream resource's
        // (int) cast is already a stable, unique-while-open id (same trick
        // EventLoop uses) - no separate id generator needed.
        $this->id = (int) $socket->getResource();
    }

    public function getId(): int
    {
        return $this->id;
    }

    /** @return resource */
    public function getResource(): mixed
    {
        return $this->socket->getResource();
    }

    /**
     * @return list<Message>
     *
     * @throws \App\Protocol\MalformedMessageException
     * @throws \App\IPC\ConnectionClosedException
     */
    public function readAvailable(): array
    {
        return $this->socket->readAvailable();
    }

    /**
     * Queues $message for sending and pushes out as much of the buffer as
     * the kernel will take without blocking. Never blocks; any remainder is
     * flushed by EventLoop ticks as the socket becomes writable.
     */
    public function write(Message $message): void
    {
        if ($this->broken) {
            return;
        }

        $this->writeBuffer .= $this->encoder->encode($message);

        if (strlen($this->writeBuffer) > self::MAX_WRITE_BUFFER_BYTES) {
            $this->markBroken();

            return;
        }

        $this->flush();
    }

    /** Whether any queued bytes are still waiting to be sent (see Master's shutdown drain). */
    public function hasPendingWrites(): bool
    {
        return $this->writeBuffer !== '';
    }

    private function flush(): void
    {
        $written = $this->socket->writeChunk($this->writeBuffer);

        if ($written === null) {
            $this->markBroken(); // peer is gone; the read side will report it and remove us

            return;
        }

        $this->writeBuffer = substr($this->writeBuffer, $written);

        if ($this->writeBuffer === '') {
            $this->unwatch(); // drained - stop asking the loop about writability

            return;
        }

        if ($this->loop !== null && !$this->awaitingWritability) {
            $this->awaitingWritability = true;
            $this->loop->addWritable($this->socket->getResource(), $this->flush(...));
        }
    }

    /**
     * The buffer always holds whole frames, so simply dropping it here can't
     * desync the peer's decoder UNLESS some of the current frame already
     * went out in an earlier flush - shutting down the sending side turns
     * that case into a clean EOF on the peer's next read instead of garbage.
     */
    private function markBroken(): void
    {
        $this->broken = true;
        $this->writeBuffer = '';
        $this->unwatch();

        @stream_socket_shutdown($this->socket->getResource(), STREAM_SHUT_WR);
    }

    private function unwatch(): void
    {
        if ($this->awaitingWritability) {
            $this->awaitingWritability = false;
            $this->loop?->removeWritable($this->socket->getResource());
        }
    }

    public function close(): void
    {
        $this->unwatch();
        $this->socket->close();
    }
}
