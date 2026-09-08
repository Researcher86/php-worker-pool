<?php

declare(strict_types=1);

namespace App\Master;

use App\Client\ClientConnection;
use App\Client\ClientRegistry;
use App\Client\PendingRequest;
use App\Client\PendingRequestRegistry;
use App\Dispatcher\Dispatcher;
use App\EventLoop\EventLoop;
use App\Metrics\MetricsCollector;
use App\Metrics\RequestMetrics;
use App\Protocol\MalformedMessageException;
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
use App\Worker\Telemetry\SharedTelemetry;
use App\Worker\Telemetry\ShmWorkerMemory;
use App\Worker\WorkerPool;
use Closure;
use RuntimeException;
use Throwable;

final class Master
{
    // How often the main loop wakes up (even with no socket activity at
    // all) to sweep for expired requests. Bounds how late a timeout can be
    // detected, not how precisely - see PendingRequestRegistry::removeExpired().
    private const float TIMEOUT_CHECK_INTERVAL_SECONDS = 1.0;

    // Room for every worker plus the ones on their way out: a slot stays
    // taken until its worker is really gone, so a pool churning at its
    // ceiling (a reload, a burst of recycling) briefly needs more slots than
    // it has workers. Capped so an absurd maxWorkers can't ask the kernel
    // for an absurd segment - past the cap the extra workers just run
    // untelemetered.
    private const int TELEMETRY_SLOTS_PER_WORKER = 2;
    private const int MAX_TELEMETRY_SLOTS = 1024;

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
     * Every default is the corresponding PHASES.md phase's own example value -
     * they're constructor parameters (rather than constants) so a deployment
     * or a test can run a Master on its own socket path and sizing without
     * editing this class.
     */
    public function __construct(
        private readonly string $socketPath = '/tmp/php-worker-pool.sock',
        // Who may connect. The socket is an unauthenticated command channel -
        // anything that can reach it can run work on every worker - so the
        // default is owner-only. Open it to a shared group (0660 + a group
        // both the Master and PHP-FPM belong to) rather than to everyone.
        private readonly int $socketMode = 0600,
        private readonly ?string $socketGroup = null,
        // PHASES.md Phase 20's bounds - the pool starts at the floor and grows
        // under load rather than starting pre-scaled.
        private readonly int $minWorkers = 2,
        private readonly int $maxWorkers = 16,
        // PHASES.md Phase 13's limit - past this many requests waiting for a
        // free worker, the queue would just grow unbounded under sustained
        // overload instead of applying backpressure.
        private readonly int $maxQueueSize = 10_000,
        // PHASES.md Phase 14: how long a client waits for a response before
        // the Master gives up on its behalf and reports a timeout instead.
        private readonly float $requestTimeoutSeconds = 30.0,
        // A different limit from the one above, for a different problem.
        // requestTimeout is about the CLIENT: stop making it wait. This is
        // about the POOL: a handler that never returns would hold its worker
        // forever, costing one slot permanently. Set above the request
        // timeout on purpose - when this fires, the request isn't late, it's
        // never finishing, so the worker is killed and replaced.
        private readonly float $workerExecutionTimeoutSeconds = 60.0,
        // PHASES.md Phase 16's safety timeout: once a shutdown signal arrives,
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
        // Closure(Worker\Request): Worker\Response. This is where business
        // logic enters the system - defined wherever the server is
        // configured (bin/server.php), never inside the runtime. Null falls
        // back to WorkerRunner's echo default.
        private readonly ?Closure $handler = null,
        /** @var (Closure(Logger): void)|null */
        // Run once inside each worker before it reports READY: the
        // application's warm-up (database connection, primed cache). Until it
        // returns, that worker is STARTING and nothing is dispatched to it -
        // so a first request never pays for a cold process. It is handed the
        // Logger below, so a warm-up reports where everything else does.
        private readonly ?Closure $bootstrap = null,
        // How long a worker may take to report READY before it is treated as
        // broken and replaced. Without a ceiling, a bootstrap that hangs
        // (an unreachable database, say) would cost one worker of capacity
        // permanently, and silently - nothing else in the system would ever
        // ask why that worker never did anything.
        private readonly float $workerBootstrapTimeoutSeconds = 30.0,
        // How long a worker gets between being told to leave and actually
        // being gone. Not the request it may still be finishing - that is
        // the execution timeout above, and a drained worker keeps it in
        // full - but the departure itself: SHUTDOWN sent, socket closed,
        // and the process still running. Small on purpose, because by then
        // there is nothing left for it to do; without it, a worker that
        // ignores its own shutdown holds its slot until the Master exits.
        private readonly float $workerDepartureTimeoutSeconds = 10.0,
    ) {
    }

    public function run(): void
    {
        // Anchored next to the socket so a Master that was SIGKILLed leaves
        // a segment its successor can find and remove.
        $telemetry = SharedTelemetry::openAt(
            $this->socketPath . '.telemetry',
            min($this->maxWorkers * self::TELEMETRY_SLOTS_PER_WORKER, self::MAX_TELEMETRY_SLOTS),
        );

        try {
            $this->pool = new WorkerPool(
                $this->minWorkers,
                new ForkedWorkerLauncher($this->handler, $telemetry, $this->bootstrap, $this->logger),
                maxWorkers: $this->maxWorkers,
                logger: $this->logger,
                recycling: $this->recycling,
                memory: new ShmWorkerMemory($telemetry),
                clock: $this->clock,
            );
        } catch (Throwable $e) {
            // The pool failing to launch (a fork that didn't) is the one
            // path out of run() that happens before the try/finally below
            // exists - and a segment is kernel-persistent, so without this
            // it would outlive the process that never even started.
            $telemetry->destroy();

            throw $e;
        }
        $this->loop = new EventLoop();
        $this->pendingRequests = new PendingRequestRegistry($this->clock);
        $this->requestMetrics = new RequestMetrics();

        $queue = new RequestQueue($this->maxQueueSize);
        $this->metrics = new MetricsCollector($this->pool, $queue, $this->pendingRequests, $this->requestMetrics);
        $autoscaler = new Autoscaler($this->pool, $queue, $this->minWorkers, $this->maxWorkers, clock: $this->clock);

        $this->dispatcher = new Dispatcher(
            $queue,
            $this->pool,
            $this->loop,
            $this->routeResponse(...),
            // Stamps the queue/execution boundary - the Dispatcher is the
            // only place that knows when a request stopped waiting for
            // capacity and started being worked on.
            fn (string $id) => $this->pendingRequests->markDispatched($id, $this->clock->now()),
        );
        $this->clients = new ClientRegistry($this->loop, $this->handleClientRequest(...), $this->handleClientDisconnect(...));
        $server = new UnixSocketServer(
            $this->socketPath,
            $this->loop,
            $this->clients->accept(...),
            $this->socketMode,
            $this->socketGroup,
        );

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

                $this->sendTimeouts();

                // Before the pool sweeps, and deliberately: dropping a
                // client we can no longer answer releases its pending
                // requests, which is capacity the sweeps below get to see
                // in the same tick rather than the next one.
                $this->clients->removeBroken();

                $this->pool->terminateStuckWorkers(
                    $this->workerExecutionTimeoutSeconds,
                    $this->workerBootstrapTimeoutSeconds,
                    $this->workerDepartureTimeoutSeconds,
                );
                $this->pool->recycleExhaustedWorkers();
                $this->pool->retireIdleWorkers();
                $autoscaler->check();

                // Last in the tick, on purpose: the sweeps above are what
                // fork new workers (a replacement, a scale-up, a reload's
                // fresh generation), and this is what starts watching their
                // sockets for the READY they are about to send. Running it
                // first would leave a worker forked in this tick unwatched
                // until the next one.
                $this->dispatcher->dispatchQueued();
            }

            $remaining = $this->shutdown($server);
        } finally {
            // Whatever's left of the same overall shutdown budget also
            // bounds waiting for workers to actually exit - the timeout is
            // one end-to-end allowance (PHASES.md: SIGTERM -> ... -> SIGKILL
            // after gracefulShutdownTimeout), not 30s of draining plus a
            // separate window on top of it.
            $this->pool->stop(max(0.0, $remaining));

            // After stop(): the workers are gone, so nothing is left to
            // publish into a segment we're about to hand back to the kernel.
            $telemetry->destroy();

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
            throw new RuntimeException('Failed to create the signal self-pipe');
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

        // PHASES.md Phase 15 - 'C': a worker can die on its own (crash,
        // OOM-kill, ...) without ever touching its socket; an idle one is
        // invisible to Dispatcher's read-based detection (it only watches
        // busy workers). SIGCHLD catches it either way -> reapCrashedWorkers().
        pcntl_signal(SIGCHLD, fn () => @fwrite($this->signalWrite, 'C'));

        // PHASES.md Phase 19 - 'H': replace every worker with a fresh one
        // without dropping a client connection or in-flight request - SIGHUP
        // is the traditional Unix "reload" signal (nginx, php-fpm).
        pcntl_signal(SIGHUP, fn () => @fwrite($this->signalWrite, 'H'));

        // PHASES.md Phase 17 - 'U': dump the current Metrics snapshot to
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
     * PHASES.md Phase 15: reap every worker that has exited and fail the
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
     *
     * The TYPE is the Master's to decide too, and for the same reason. Both
     * sides of the pool speak one wire format, so a client's frame type used
     * to be forwarded verbatim - and a client that sent SHUTDOWN had it
     * delivered to a worker, which exited exactly as if the Master had
     * retired it. That turned "can reach the socket" into "can churn the
     * pool": one frame per worker, a crash and a fork each time, from a peer
     * that is only supposed to be able to ask for work.
     *
     * A frame of any other type is therefore a protocol violation rather
     * than a request, and is treated exactly like a malformed one -
     * ClientRegistry drops the connection - because a peer that isn't
     * speaking the protocol it claims to has nothing left to say that can
     * be trusted.
     *
     * The guard is the single place that decides this, which is why the
     * dispatch below still passes $request->type through rather than
     * restating REQUEST: the day a client is allowed to send some second
     * type, this condition is the one thing that has to loosen.
     *
     * @throws MalformedMessageException
     */
    private function handleClientRequest(ClientConnection $client, Message $request): void
    {
        if ($request->type !== MessageType::REQUEST) {
            throw new MalformedMessageException(sprintf(
                'Clients may only send "%s" frames, got "%s"',
                MessageType::REQUEST->value,
                $request->type->value,
            ));
        }

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
        // mid-request (PHASES.md Phase 15).
        $response->type === MessageType::RESPONSE
            ? $this->requestMetrics->recordCompleted()
            : $this->requestMetrics->recordFailed();

        $this->recordLatency($pending);

        $pending->client->write(new Message($response->type, $pending->originalId, $response->payload));
    }

    /**
     * Splits a finished request's life into the two halves that have
     * different causes and different fixes: time spent waiting for a free
     * worker (the pool is too small, or overloaded) and time the handler
     * itself took (the code is slow). A single end-to-end number hides
     * which one it was, which is exactly when you need to know.
     *
     * Only requests that actually reached a worker are measured - one
     * rejected or timed out while still queued has no execution time to
     * report, and averaging a zero into it would flatter the numbers.
     */
    private function recordLatency(PendingRequest $pending): void
    {
        $now = $this->clock->now();
        $queued = $pending->queuedSeconds();
        $execution = $pending->executionSeconds($now);

        if ($queued === null || $execution === null) {
            return;
        }

        $this->requestMetrics->queueWait->record($queued);
        $this->requestMetrics->execution->record($execution);
        $this->requestMetrics->endToEnd->record($now - $pending->acceptedAt);
    }

    /**
     * PHASES.md Phase 16: on a shutdown signal, stop taking new connections
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

        // Absorb what clients have already sent but we haven't read yet.
        // Those bytes are ours - the request was accepted the moment it
        // landed in our socket - but until this pass runs they exist
        // nowhere the drain below can see: PendingRequestRegistry is still
        // empty for them. Without it, a SIGTERM arriving just after a burst
        // was written found count() === 0, skipped draining entirely, and
        // dropped every one of those requests silently. One non-blocking
        // pass over every ready fd is enough, and it can't block shutdown:
        // it reads what is already there and returns.
        //
        // The cutoff is here on purpose. A request written after this pass
        // is one that arrived after we began shutting down, and it gets the
        // connection closing under it - the same answer any server gives
        // for "you were too late".
        $this->loop->tick(0.0);

        $deadline = $this->clock->now() + $this->gracefulShutdownTimeoutSeconds;

        while ($this->pendingRequests->count() > 0 && $this->clock->now() < $deadline) {
            $this->loop->tick(min($deadline - $this->clock->now(), self::TIMEOUT_CHECK_INTERVAL_SECONDS));

            $this->dispatcher->dispatchQueued(); // "finish QUEUED requests" too, not just in-flight ones
            $this->sendTimeouts();

            // A client that can no longer be written to would otherwise
            // keep its pending entries here for the whole drain window,
            // holding shutdown open to deliver answers it cannot receive.
            $this->clients->removeBroken();
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
