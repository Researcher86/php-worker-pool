<?php

declare(strict_types=1);

namespace App\Master;

use App\Client\ClientConnection;
use App\Client\ClientRegistry;
use App\EventLoop\EventLoop;
use App\Protocol\Message;
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

        // Phase 11 will route a decoded request through a Dispatcher (queue
        // + dispatch to $pool) and track it in a pending-requests registry
        // so the eventual response reaches this same client.
        $clients = new ClientRegistry($loop, function (ClientConnection $client, Message $message): void {
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
