<?php

declare(strict_types=1);

namespace App\Client;

use App\IPC\Socket;
use App\Protocol\Message;

/**
 * Master-side handle for one connected client: its id and its socket.
 *
 * Unlike WorkerProcess there's no state machine here — a client is either
 * tracked by ClientRegistry (connected) or it isn't (gone); nothing else
 * about it changes over time yet. The read/write buffering PLAN.md's Phase
 * 10 asks for is already provided by Socket (its MessageDecoder buffers
 * partial reads) — this class doesn't need its own copy.
 */
final readonly class ClientConnection
{
    private int $id;

    public function __construct(
        private Socket $socket,
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

    public function write(Message $message): void
    {
        $this->socket->write($message);
    }

    public function close(): void
    {
        $this->socket->close();
    }
}
