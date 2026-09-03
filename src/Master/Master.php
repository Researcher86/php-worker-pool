<?php

declare(strict_types=1);

namespace App\Master;

use App\Client\ClientConnection;
use App\Client\ClientRegistry;
use App\Client\PendingRequestRegistry;
use App\Dispatcher\Dispatcher;
use App\EventLoop\EventLoop;
use App\Protocol\Message;
use App\Queue\RequestQueue;
use App\Server\UnixSocketServer;
use App\Worker\WorkerPool;

final class Master
{
    private const SOCKET_PATH = '/tmp/php-worker-pool.sock';

    private bool $running = true;

    public function run(): void
    {
        $pool = new WorkerPool(4);
        $loop = new EventLoop();
        $pendingRequests = new PendingRequestRegistry();

        // A worker's response carries the id the Master dispatched it
        // under, not the client's original id - resolve() maps back to
        // both, so the reply can go out on the right socket under the id
        // that client is actually expecting.
        $dispatcher = new Dispatcher(new RequestQueue(), $pool, $loop, function (Message $response) use ($pendingRequests): void {
            $pending = $pendingRequests->resolve($response->id);

            if ($pending === null) {
                return; // unknown or already-handled id - nothing to route it to
            }

            $pending->client->write(new Message($response->type, $pending->originalId, $response->payload));
        });

        // Each client request is dispatched under a Master-assigned id
        // (see PendingRequestRegistry) rather than the client's own, so two
        // clients (or one client, by mistake) picking the same id can never
        // misroute a response.
        $clients = new ClientRegistry($loop, function (ClientConnection $client, Message $request) use ($dispatcher, $pendingRequests): void {
            $dispatchId = $pendingRequests->register($client, $request->id);

            $dispatcher->dispatch(new Message($request->type, $dispatchId, $request->payload));
        });

        $server = new UnixSocketServer(self::SOCKET_PATH, $loop, $clients->accept(...));

        // Catch SIGINT/SIGTERM so the socket file is removed and workers are
        // reaped on shutdown instead of leaving a stale socket behind.
        $this->running = true;
        pcntl_async_signals(true);
        pcntl_signal(SIGINT, $this->stop(...));
        pcntl_signal(SIGTERM, $this->stop(...));

        try {
            // Accept client connections until a shutdown signal arrives. The
            // blocking stream_select() inside tick() returns early on a caught
            // signal (EINTR), which lets the loop check the flag and exit.
            // PHPStan's while.alwaysTrue can't see $this->running flip to false
            // because that only happens inside the signal-handler callback.
            // @phpstan-ignore-next-line while.alwaysTrue
            while ($this->running) {
                $loop->tick();
            }
        } finally {
            $server->close();
            $pool->stop();
        }
    }

    private function stop(): void
    {
        $this->running = false;
    }
}
