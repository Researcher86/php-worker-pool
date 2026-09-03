<?php

declare(strict_types=1);

namespace App\Master;

use App\Dispatcher\Dispatcher;
use App\EventLoop\EventLoop;
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
        $dispatcher = new Dispatcher(new RequestQueue(), $pool, $loop);

        $server = new UnixSocketServer(
            self::SOCKET_PATH,
            $loop,
            // Phase 10 will read/decode client requests here; for now we only
            // need the socket accepted for a client to be able to connect.
            function (): void {
            },
        );

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