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
    private const string SOCKET_PATH = '/tmp/php-worker-pool.sock';

    // PLAN.md Phase 13's example limit - past this many requests waiting for
    // a free worker, the queue would just grow unbounded under sustained
    // overload instead of applying backpressure.
    private const int MAX_QUEUE_SIZE = 10_000;

    // PLAN.md Phase 14: how long a client waits for a response before the
    // Master gives up on its behalf and reports a timeout instead.
    private const float REQUEST_TIMEOUT_SECONDS = 30.0;

    // How often the main loop wakes up (even with no socket activity at
    // all) to sweep for expired requests. Bounds how late a timeout can be
    // detected, not how precisely - see PendingRequestRegistry::removeExpired().
    private const float TIMEOUT_CHECK_INTERVAL_SECONDS = 1.0;

    // PLAN.md Phase 16's safety timeout: once a shutdown signal arrives,
    // queued and in-flight requests get this long, total, to finish before
    // the Master gives up on whoever's left and force-stops the workers.
    private const float GRACEFUL_SHUTDOWN_TIMEOUT_SECONDS = 30.0;

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

        // PLAN.md Phase 15: a worker can die on its own (crash, OOM-kill,
        // ...) without ever touching its socket - an idle one wouldn't be
        // noticed by Dispatcher's read-based detection at all, since it's
        // only watching busy workers. SIGCHLD catches it immediately either
        // way and keeps the pool at full strength.
        pcntl_signal(SIGCHLD, function () use ($pool, $pendingRequests): void {
            foreach ($pool->reapDeadWorkers() as $crash) {
                if ($crash->lostRequestId === null) {
                    continue;
                }

                $pending = $pendingRequests->resolve($crash->lostRequestId);

                if ($pending !== null) {
                    $pending->client->write(new Message(MessageType::ERROR, $pending->originalId, ['error' => 'worker_crashed']));
                }
            }
        });

        $remaining = 0.0;

        try {
            // Accept client connections until a shutdown signal arrives. The
            // blocking stream_select() inside tick() returns early on a caught
            // signal (EINTR), which lets the loop check the flag and exit -
            // and, regardless of a signal, at least once every
            // TIMEOUT_CHECK_INTERVAL_SECONDS, which is what lets requests
            // past their deadline actually get noticed.
            while ($this->running) {
                $loop->tick(self::TIMEOUT_CHECK_INTERVAL_SECONDS);

                $this->sendTimeouts($pendingRequests);
            }

            $remaining = $this->shutdown($server, $loop, $pendingRequests);
        } finally {
            // Whatever's left of the same overall shutdown budget also
            // bounds waiting for workers to actually exit - the timeout is
            // one end-to-end allowance (PLAN.md: SIGTERM -> ... -> SIGKILL
            // after gracefulShutdownTimeout), not 30s of draining plus a
            // separate window on top of it.
            $pool->stop(max(0.0, $remaining));
        }
    }

    /**
     * PLAN.md Phase 16: on a shutdown signal, stop taking new connections
     * immediately, then give queued/in-flight requests a bounded window to
     * actually finish (normal traffic keeps flowing through the same $loop
     * the whole time - workers and already-connected clients don't know
     * anything is happening) before giving up on whoever's still pending.
     *
     * @return float seconds left of the overall shutdown budget once
     *         draining stopped (0 or negative if the timeout was reached)
     */
    private function shutdown(UnixSocketServer $server, EventLoop $loop, PendingRequestRegistry $pendingRequests): float
    {
        $server->close();

        $deadline = microtime(true) + self::GRACEFUL_SHUTDOWN_TIMEOUT_SECONDS;

        while ($pendingRequests->count() > 0 && microtime(true) < $deadline) {
            $loop->tick(min($deadline - microtime(true), self::TIMEOUT_CHECK_INTERVAL_SECONDS));

            $this->sendTimeouts($pendingRequests);
        }

        // Whatever's left didn't finish inside the safety timeout - tell
        // those clients rather than just abandoning them (pool->stop(),
        // right after this returns, is about to forcibly end the workers
        // still holding some of these anyway).
        foreach ($pendingRequests->drainAll() as $stillPending) {
            $stillPending->client->write(new Message(MessageType::ERROR, $stillPending->originalId, ['error' => 'server_shutting_down']));
        }

        return $deadline - microtime(true);
    }

    private function sendTimeouts(PendingRequestRegistry $pendingRequests): void
    {
        foreach ($pendingRequests->removeExpired(microtime(true)) as $expired) {
            $expired->client->write(new Message(MessageType::ERROR, $expired->originalId, ['error' => 'request_timeout']));
        }
    }

    private function stop(): void
    {
        $this->running = false;
    }
}
