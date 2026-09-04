<?php

declare(strict_types=1);

namespace App\Master;

use App\Client\ClientConnection;
use App\Client\ClientRegistry;
use App\Client\PendingRequestRegistry;
use App\Dispatcher\Dispatcher;
use App\EventLoop\EventLoop;
use App\Metrics\MetricsCollector;
use App\Metrics\RequestMetrics;
use App\Protocol\Message;
use App\Protocol\MessageType;
use App\Queue\RequestQueue;
use App\Server\UnixSocketServer;
use App\Support\Clock;
use App\Support\Logger;
use App\Support\StderrLogger;
use App\Support\SystemClock;
use App\Worker\Autoscaler;
use App\Worker\ForkedWorkerLauncher;
use App\Worker\RecyclingPolicy;
use App\Worker\WorkerPool;

final class Master
{
    // How often the main loop wakes up (even with no socket activity at
    // all) to sweep for expired requests. Bounds how late a timeout can be
    // detected, not how precisely - see PendingRequestRegistry::removeExpired().
    private const float TIMEOUT_CHECK_INTERVAL_SECONDS = 1.0;

    private bool $running = true;

    // Wired in run() and alive for that one run()'s duration. Properties
    // rather than locals threaded through use() closures: the private
    // handlers below read them directly, keeping run() itself to just
    // construction and the loop.
    private WorkerPool $pool;
    private EventLoop $loop;
    private PendingRequestRegistry $pendingRequests;
    private Dispatcher $dispatcher;
    private ClientRegistry $clients;
    private RequestMetrics $requestMetrics;
    private MetricsCollector $metrics;

    /** @var resource read end of the signal self-pipe (see registerSignalHandling()) */
    private mixed $signalRead;

    /** @var resource write end of the signal self-pipe */
    private mixed $signalWrite;

    /**
     * Every default is the corresponding PLAN.md phase's own example value -
     * they're constructor parameters (rather than constants) so a deployment
     * or a test can run a Master on its own socket path and sizing without
     * editing this class.
     */
    public function __construct(
        private readonly string $socketPath = '/tmp/php-worker-pool.sock',
        // PLAN.md Phase 20's bounds - the pool starts at the floor and grows
        // under load rather than starting pre-scaled.
        private readonly int $minWorkers = 2,
        private readonly int $maxWorkers = 16,
        // PLAN.md Phase 13's limit - past this many requests waiting for a
        // free worker, the queue would just grow unbounded under sustained
        // overload instead of applying backpressure.
        private readonly int $maxQueueSize = 10_000,
        // PLAN.md Phase 14: how long a client waits for a response before
        // the Master gives up on its behalf and reports a timeout instead.
        private readonly float $requestTimeoutSeconds = 30.0,
        // A different limit from the one above, for a different problem.
        // requestTimeout is about the CLIENT: stop making it wait. This is
        // about the POOL: a handler that never returns would hold its worker
        // forever, costing one slot permanently. Set above the request
        // timeout on purpose - when this fires, the request isn't late, it's
        // never finishing, so the worker is killed and replaced.
        private readonly float $workerExecutionTimeoutSeconds = 60.0,
        // PLAN.md Phase 16's safety timeout: once a shutdown signal arrives,
        // queued and in-flight requests get this long, total, to finish
        // before the Master gives up on whoever's left and force-stops the
        // workers.
        private readonly float $gracefulShutdownTimeoutSeconds = 30.0,
        private readonly Logger $logger = new StderrLogger(),
        private readonly Clock $clock = new SystemClock(),
        // When to replace a worker with a fresh process. A long-running PHP
        // process accumulates leaked memory and stale static state that a
        // per-request runtime discards for free, so persistent-worker
        // runtimes all offer this (php-fpm's pm.max_requests, RoadRunner's
        // max_jobs/max_memory). The defaults here are conservative rather
        // than off: recycling costs one fork and never interrupts a request,
        // so the cheap insurance is worth taking by default.
        private readonly RecyclingPolicy $recycling = new RecyclingPolicy(
            maxRequests: 10_000,
            maxLifetime: 3600.0,
            maxMemoryBytes: 256 * 1024 * 1024,
        ),
        // The application's request handler, run inside each worker:
        // \Closure(Worker\Request): Worker\Response. This is where business
        // logic enters the system - defined wherever the server is
        // configured (bin/server.php), never inside the runtime. Null falls
        // back to WorkerRunner's echo default.
        private readonly ?\Closure $handler = null,
    ) {
    }

    public function run(): void
    {
        $this->pool = new WorkerPool(
            $this->minWorkers,
            new ForkedWorkerLauncher($this->handler),
            maxWorkers: $this->maxWorkers,
            logger: $this->logger,
            recycling: $this->recycling,
            clock: $this->clock,
        );
        $this->loop = new EventLoop();
        $this->pendingRequests = new PendingRequestRegistry($this->clock);
        $this->requestMetrics = new RequestMetrics();

        $queue = new RequestQueue($this->maxQueueSize);
        $this->metrics = new MetricsCollector($this->pool, $queue, $this->pendingRequests, $this->requestMetrics);
        $autoscaler = new Autoscaler($this->pool, $queue, $this->minWorkers, $this->maxWorkers, clock: $this->clock);

        $this->dispatcher = new Dispatcher($queue, $this->pool, $this->loop, $this->routeResponse(...));
        $this->clients = new ClientRegistry($this->loop, $this->handleClientRequest(...), $this->handleClientDisconnect(...));
        $server = new UnixSocketServer($this->socketPath, $this->loop, $this->clients->accept(...));

        $this->registerSignalHandling();

        $remaining = 0.0;

        try {
            // Accept client connections until a shutdown signal arrives. The
            // blocking stream_select() inside tick() returns early on a caught
            // signal (EINTR), which lets the loop check the flag and exit -
            // and, regardless of a signal, at least once every
            // TIMEOUT_CHECK_INTERVAL_SECONDS, which is what lets requests
            // past their deadline actually get noticed.
            while ($this->running) {
                $this->loop->tick(self::TIMEOUT_CHECK_INTERVAL_SECONDS);

                // Capacity can appear outside any dispatch event (a crash
                // replacement reaped in during this tick, a scale-up, a
                // reload's fresh generation) - give queued requests a chance
                // to land on it.
                $this->dispatcher->dispatchQueued();

                $this->sendTimeouts();
                $this->pool->terminateStuckWorkers($this->workerExecutionTimeoutSeconds);
                $this->pool->recycleExhaustedWorkers();
                $this->pool->retireIdleWorkers();
                $autoscaler->check();
            }

            $remaining = $this->shutdown($server);
        } finally {
            // Whatever's left of the same overall shutdown budget also
            // bounds waiting for workers to actually exit - the timeout is
            // one end-to-end allowance (PLAN.md: SIGTERM -> ... -> SIGKILL
            // after gracefulShutdownTimeout), not 30s of draining plus a
            // separate window on top of it.
            $this->pool->stop(max(0.0, $remaining));

            $this->loop->removeReadable($this->signalRead);
            fclose($this->signalRead);
            fclose($this->signalWrite);
        }
    }

    /**
     * The self-pipe pattern: each signal handler writes ONE identifying byte
     * to a pipe whose read end is registered with the same EventLoop as
     * every other fd, and drainSignalPipe() does the actual work from
     * ordinary main-loop context on the very next tick (the signal
     * interrupts the blocking select, so "next tick" is immediate).
     *
     * Why not do the work in the handler: with pcntl_async_signals(true) a
     * handler body runs between ANY two statements of the interrupted code.
     * Reaping/reloading the pool from there interleaves with pool operations
     * already in progress (WorkerPool defends itself with sigprocmask, but
     * that shouldn't be the only line of defense), writing to a client from
     * there can corrupt that client's unfinished buffered write, and
     * reload() used to fork new workers from *inside signal context*. A
     * one-byte write to a dedicated pipe is async-signal-safe by
     * construction; everything else now runs where the rest of the code
     * already runs.
     *
     * SIGINT/SIGTERM stay a plain flag: setting one bool is just as safe,
     * and the main loop checks it right after the tick the signal interrupts.
     */
    private function registerSignalHandling(): void
    {
        $pipe = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($pipe === false) {
            throw new \RuntimeException('Failed to create the signal self-pipe');
        }

        [$this->signalRead, $this->signalWrite] = $pipe;
        stream_set_blocking($this->signalRead, false);
        stream_set_blocking($this->signalWrite, false);

        $this->loop->addReadable($this->signalRead, $this->drainSignalPipe(...));

        pcntl_async_signals(true);

        // Catch SIGINT/SIGTERM so the socket file is removed and workers are
        // reaped on shutdown instead of leaving a stale socket behind.
        pcntl_signal(SIGINT, $this->stop(...));
        pcntl_signal(SIGTERM, $this->stop(...));

        // PLAN.md Phase 15 - 'C': a worker can die on its own (crash,
        // OOM-kill, ...) without ever touching its socket; an idle one is
        // invisible to Dispatcher's read-based detection (it only watches
        // busy workers). SIGCHLD catches it either way -> reapCrashedWorkers().
        pcntl_signal(SIGCHLD, fn () => @fwrite($this->signalWrite, 'C'));

        // PLAN.md Phase 19 - 'H': replace every worker with a fresh one
        // without dropping a client connection or in-flight request - SIGHUP
        // is the traditional Unix "reload" signal (nginx, php-fpm).
        pcntl_signal(SIGHUP, fn () => @fwrite($this->signalWrite, 'H'));

        // PLAN.md Phase 17 - 'U': dump the current Metrics snapshot to
        // stdout on demand - `kill -USR1 <pid>` is the usual Unix convention
        // for "report your stats now" (nginx and php-fpm again), and needs
        // no new wire protocol or endpoint.
        pcntl_signal(SIGUSR1, fn () => @fwrite($this->signalWrite, 'U'));
    }

    /**
     * Main-loop side of the self-pipe: read whatever signal bytes have
     * accumulated and act on each distinct one once. Coalescing repeats is
     * deliberate - five SIGCHLDs still need only one reap pass (it drains
     * every zombie in one go), and reload() is already a no-op while one is
     * in progress. On an interrupted (EINTR) tick every registered handler
     * runs regardless of readiness, so an empty read here is normal.
     */
    private function drainSignalPipe(): void
    {
        $bytes = fread($this->signalRead, 64);

        if ($bytes === false || $bytes === '') {
            return;
        }

        foreach (array_unique(str_split($bytes)) as $byte) {
            match ($byte) {
                'C' => $this->reapCrashedWorkers(),
                'H' => $this->pool->reload(),
                'U' => $this->dumpMetrics(),
                default => null,
            };
        }
    }

    /**
     * PLAN.md Phase 15: reap every worker that has exited and fail the
     * request each crashed one was holding, so its client hears
     * worker_crashed now instead of waiting out the request timeout. Runs in
     * main-loop context (via the self-pipe), so writing to clients from here
     * is ordinary sequential code - no signal reentrancy to worry about.
     */
    private function reapCrashedWorkers(): void
    {
        foreach ($this->pool->reapDeadWorkers() as $crash) {
            if ($crash->lostRequestId === null) {
                continue;
            }

            $pending = $this->pendingRequests->resolve($crash->lostRequestId);

            if ($pending !== null) {
                $this->requestMetrics->recordFailed();
                $pending->client->write(new Message(MessageType::ERROR, $pending->originalId, ['error' => 'worker_crashed']));
            }
        }
    }

    private function dumpMetrics(): void
    {
        echo $this->metrics->snapshot()->format();
    }

    /**
     * Each client request is dispatched under a Master-assigned id (see
     * PendingRequestRegistry) rather than the client's own, so two clients
     * (or one client, by mistake) picking the same id can never misroute a
     * response.
     */
    private function handleClientRequest(ClientConnection $client, Message $request): void
    {
        $this->requestMetrics->recordReceived();

        $dispatchId = $this->pendingRequests->register($client, $request->id, $this->requestTimeoutSeconds);

        if (!$this->dispatcher->dispatch(new Message($request->type, $dispatchId, $request->payload))) {
            $this->pendingRequests->resolve($dispatchId); // never actually dispatched - nothing to route a response to later
            $client->write(new Message(MessageType::ERROR, $request->id, ['error' => 'server_overloaded']));
        }
    }

    /**
     * The client is gone - any request of theirs still in flight will never
     * have anywhere to deliver its response. Without this, those entries
     * would just sit until their timeout deadline for no reason; the worker
     * handling one is still doing real work that's now wasted either way.
     */
    private function handleClientDisconnect(ClientConnection $client): void
    {
        foreach ($this->pendingRequests->removeByClient($client) as $orphaned) {
            $this->requestMetrics->recordFailed();
        }
    }

    /**
     * A worker's response carries the id the Master dispatched it under, not
     * the client's original id - resolve() maps back to both, so the reply
     * can go out on the right socket under the id that client is actually
     * expecting.
     */
    private function routeResponse(Message $response): void
    {
        $pending = $this->pendingRequests->resolve($response->id);

        if ($pending === null) {
            return; // unknown or already-handled id - nothing to route it to
        }

        // A RESPONSE is a real worker reply; anything else here is the
        // worker_crashed error Dispatcher synthesizes when the worker died
        // mid-request (PLAN.md Phase 15).
        $response->type === MessageType::RESPONSE
            ? $this->requestMetrics->recordCompleted()
            : $this->requestMetrics->recordFailed();

        $pending->client->write(new Message($response->type, $pending->originalId, $response->payload));
    }

    /**
     * PLAN.md Phase 16: on a shutdown signal, stop taking new connections
     * immediately, then give queued/in-flight requests a bounded window to
     * actually finish (normal traffic keeps flowing through the same loop
     * the whole time - workers and already-connected clients don't know
     * anything is happening) before giving up on whoever's still pending.
     * The signal pipe stays registered, so worker crashes during the drain
     * are still handled.
     *
     * @return float seconds left of the overall shutdown budget once
     *         draining stopped (0 or negative if the timeout was reached)
     */
    private function shutdown(UnixSocketServer $server): float
    {
        $server->close();

        $deadline = $this->clock->now() + $this->gracefulShutdownTimeoutSeconds;

        while ($this->pendingRequests->count() > 0 && $this->clock->now() < $deadline) {
            $this->loop->tick(min($deadline - $this->clock->now(), self::TIMEOUT_CHECK_INTERVAL_SECONDS));

            $this->dispatcher->dispatchQueued(); // "finish QUEUED requests" too, not just in-flight ones
            $this->sendTimeouts();
        }

        // Whatever's left didn't finish inside the safety timeout - tell
        // those clients rather than just abandoning them (pool->stop(),
        // right after this returns, is about to forcibly end the workers
        // still holding some of these anyway).
        foreach ($this->pendingRequests->drainAll() as $stillPending) {
            $this->requestMetrics->recordFailed();
            $stillPending->client->write(new Message(MessageType::ERROR, $stillPending->originalId, ['error' => 'server_shutting_down']));
        }

        // Client writes are buffered and only leave the process inside
        // tick() (see ClientConnection) - without this, the error frames
        // just queued (or a real response a slow client hasn't drained yet)
        // would be silently discarded when the process exits moments from
        // now. Bounded by whatever remains of the same shutdown budget.
        while ($this->clients->hasPendingWrites() && $this->clock->now() < $deadline) {
            $this->loop->tick(min($deadline - $this->clock->now(), 0.1));
        }

        return $deadline - $this->clock->now();
    }

    private function sendTimeouts(): void
    {
        foreach ($this->pendingRequests->removeExpired($this->clock->now()) as $expired) {
            $expired->client->write(new Message(MessageType::ERROR, $expired->originalId, ['error' => 'request_timeout']));
        }
    }

    private function stop(): void
    {
        $this->running = false;
    }
}
