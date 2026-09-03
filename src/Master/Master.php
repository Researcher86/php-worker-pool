<?php

declare(strict_types=1);

namespace App\Master;

use App\Client\ClientConnection;
use App\Client\ClientRegistry;
use App\Client\PendingRequestRegistry;
use App\Dispatcher\Dispatcher;
use App\EventLoop\EventLoop;
use App\Protocol\Message;
use App\Protocol\MessageType;
use App\Queue\RequestQueue;
use App\Server\UnixSocketServer;
use App\Worker\WorkerPool;

final class Master
{
    private const SOCKET_PATH = '/tmp/php-worker-pool.sock';

    // PLAN.md Phase 13's example limit - past this many requests waiting for
    // a free worker, the queue would just grow unbounded under sustained
    // overload instead of applying backpressure.
    private const MAX_QUEUE_SIZE = 10_000;

    // PLAN.md Phase 14: how long a client waits for a response before the
    // Master gives up on its behalf and reports a timeout instead.
    private const REQUEST_TIMEOUT_SECONDS = 30.0;

    // How often the main loop wakes up (even with no socket activity at
    // all) to sweep for expired requests. Bounds how late a timeout can be
    // detected, not how precisely - see PendingRequestRegistry::removeExpired().
    private const TIMEOUT_CHECK_INTERVAL_SECONDS = 1.0;

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
        $dispatcher = new Dispatcher(
            new RequestQueue(self::MAX_QUEUE_SIZE),
            $pool,
            $loop,
            function (Message $response) use ($pendingRequests): void {
                $pending = $pendingRequests->resolve($response->id);

                if ($pending === null) {
                    return; // unknown or already-handled id - nothing to route it to
                }

                $pending->client->write(new Message($response->type, $pending->originalId, $response->payload));
            }
        );

        // Each client request is dispatched under a Master-assigned id
        // (see PendingRequestRegistry) rather than the client's own, so two
        // clients (or one client, by mistake) picking the same id can never
        // misroute a response.
        $clients = new ClientRegistry($loop, function (ClientConnection $client, Message $request) use ($dispatcher, $pendingRequests): void {
            $dispatchId = $pendingRequests->register($client, $request->id, self::REQUEST_TIMEOUT_SECONDS);

            $dispatched = $dispatcher->dispatch(new Message($request->type, $dispatchId, $request->payload));

            if (!$dispatched) {
                $pendingRequests->resolve($dispatchId); // never actually dispatched - nothing to route a response to later
                $client->write(new Message(MessageType::ERROR, $request->id, ['error' => 'server_overloaded']));
            }
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
            // signal (EINTR), which lets the loop check the flag and exit -
            // and, regardless of a signal, at least once every
            // TIMEOUT_CHECK_INTERVAL_SECONDS, which is what lets requests
            // past their deadline actually get noticed.
            // PHPStan's while.alwaysTrue can't see $this->running flip to false
            // because that only happens inside the signal-handler callback.
            // @phpstan-ignore-next-line while.alwaysTrue
            while ($this->running) {
                $loop->tick(self::TIMEOUT_CHECK_INTERVAL_SECONDS);

                foreach ($pendingRequests->removeExpired(microtime(true)) as $expired) {
                    $expired->client->write(new Message(MessageType::ERROR, $expired->originalId, ['error' => 'request_timeout']));
                }
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
