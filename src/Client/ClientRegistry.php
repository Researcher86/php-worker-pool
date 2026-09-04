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
 * Master (see PHASES.md Phase 10, "A disconnected client must not crash the
 * Master").
 */
final class ClientRegistry
{
    /** @var array<int, ClientConnection> */
    private array $clients = [];

    /** @var \Closure(ClientConnection, Message): void */
    private \Closure $onRequest;

    /** @var \Closure(ClientConnection): void */
    private \Closure $onDisconnect;

    /**
     * @param callable(ClientConnection, Message): void $onRequest invoked
     *        for every message successfully decoded from a client
     * @param callable(ClientConnection): void|null $onDisconnect invoked
     *        once a client is dropped (disconnected or sent something
     *        unparseable) - the caller's chance to clean up anything it
     *        was tracking against this client (e.g. a request still
     *        awaiting this client's response). Defaults to a no-op for
     *        callers that don't track anything per-client.
     */
    public function __construct(
        private readonly EventLoop $loop,
        callable $onRequest,
        ?callable $onDisconnect = null,
    ) {
        $this->onRequest = \Closure::fromCallable($onRequest);
        $this->onDisconnect = \Closure::fromCallable($onDisconnect ?? static function (ClientConnection $client): void {
        });
    }

    public function count(): int
    {
        return count($this->clients);
    }

    /**
     * Whether any connected client still has buffered response bytes waiting
     * to go out. Master's shutdown uses this to keep ticking the loop until
     * the final frames actually leave the process (buffered writes only make
     * progress inside EventLoop::tick()).
     */
    public function hasPendingWrites(): bool
    {
        foreach ($this->clients as $client) {
            if ($client->hasPendingWrites()) {
                return true;
            }
        }

        return false;
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
        $client = new ClientConnection(new Socket($socket), $this->loop);
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

        ($this->onDisconnect)($client);
    }
}
