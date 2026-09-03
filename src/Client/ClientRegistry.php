<?php

declare(strict_types=1);

namespace App\Client;

use App\EventLoop\EventLoop;
use App\IPC\ConnectionClosedException;
use App\IPC\Socket;
use App\Protocol\MalformedMessageException;
use App\Protocol\Message;

/**
 * Tracks every currently-connected client and keeps each one's socket
 * registered with the shared EventLoop for as long as it's connected.
 *
 * A client is removed the moment its connection can no longer be trusted -
 * either it disconnected (ConnectionClosedException) or it sent bytes that
 * don't parse as a message (MalformedMessageException, e.g. a framing
 * desync). Both are handled the same way: drop the client, don't crash the
 * Master (see PLAN.md Phase 10, "A disconnected client must not crash the
 * Master").
 */
final class ClientRegistry
{
    /** @var array<int, ClientConnection> */
    private array $clients = [];

    /** @var \Closure(ClientConnection, Message): void */
    private \Closure $onRequest;

    /**
     * @param callable(ClientConnection, Message): void $onRequest invoked
     *        for every message successfully decoded from a client
     */
    public function __construct(
        private readonly EventLoop $loop,
        callable $onRequest,
    ) {
        $this->onRequest = \Closure::fromCallable($onRequest);
    }

    public function count(): int
    {
        return count($this->clients);
    }

    /**
     * Starts tracking a newly accepted client connection.
     *
     * @param resource $socket a client socket accepted from a server
     *                         listener (expected to already be non-blocking,
     *                         as UnixSocketServer::accept() sets it)
     */
    public function accept(mixed $socket): void
    {
        $client = new ClientConnection(new Socket($socket));
        $this->clients[$client->getId()] = $client;

        $this->loop->addReadable($client->getResource(), function () use ($client): void {
            try {
                foreach ($client->readAvailable() as $message) {
                    ($this->onRequest)($client, $message);
                }
            } catch (ConnectionClosedException | MalformedMessageException) {
                $this->remove($client);
            }
        });
    }

    private function remove(ClientConnection $client): void
    {
        $this->loop->removeReadable($client->getResource());
        $client->close();

        unset($this->clients[$client->getId()]);
    }
}
