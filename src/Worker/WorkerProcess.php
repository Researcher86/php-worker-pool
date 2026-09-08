<?php

declare(strict_types=1);

namespace App\Worker;

use App\IPC\Socket;
use App\Protocol\Message;
use LogicException;

/**
 * Master-side handle for one worker process: its pid, its socket, its
 * lifecycle state, and the counters recycling decides on.
 *
 * State machine (see WorkerState). A worker starts STARTING and becomes
 * dispatchable only when it says so - the readiness handshake. A fork does
 * not mean a usable worker: the application's own bootstrap (database
 * connection, warm cache) runs first, and the Master cannot see when that
 * finished. Only the worker can, so only the worker says it, with one READY
 * message.
 *
 *   STARTING ──markReady()──> IDLE ──beginRequest()──> BUSY
 *     │                         │                       │
 *     │                         │       drain()         │      drain()
 *     │        drain()          └───────────┬───────────┴──────────┐
 *     └────────────────────────────────────►▼                      ▼
 *                                       DRAINING ◀─finishRequest()─DRAINING
 *                                       (no work)                (still working)
 *                                           │
 *                                           │  stop()   (legal from any state
 *                                           ▼            but DEAD, so shutdown
 *                                       STOPPING         is never blocked)
 *                                           │
 *                                           ▼
 *   any state ──markDead()──> DEAD (terminal, no way out)
 *
 * BUSY ──finishRequest()──> IDLE, never back to STARTING: readiness is a
 * one-time fact about a process, not something it re-earns per request.
 *
 * Two things are tracked separately on purpose: the STATE says whether new
 * work may be dispatched here, and $currentRequestId says whether work is
 * happening right now. DRAINING is the combination the old design needed a
 * second map for - "no new requests, but leave whatever it's doing alone".
 * A worker drained while BUSY keeps its request id until it answers.
 *
 * "Available" (isAvailable()) means IDLE - the one state a new request may
 * be dispatched from.
 */
final class WorkerProcess
{
    /**
     * The whole state machine, stated once: event => (state it is legal
     * from => state it leads to). Anything absent is illegal and throws.
     *
     * Keyed by ->name because enum cases cannot be array keys. Written as a
     * table rather than five hand-rolled guards so that adding a state means
     * editing one place and immediately seeing every event it has to answer
     * for - the alternative is an `if ($state === ...)` in fifteen methods,
     * which is how state machines rot.
     *
     *   FROM        ready       dispatch    respond     drain       stop        die
     *   ─────────────────────────────────────────────────────────────────────────
     *   STARTING    IDLE        -           -           DRAINING    STOPPING    DEAD
     *   IDLE        -           BUSY        -           DRAINING    STOPPING    DEAD
     *   BUSY        -           -           IDLE        DRAINING    STOPPING    DEAD
     *   DRAINING    DRAINING    -           DRAINING    DRAINING    STOPPING    DEAD
     *   STOPPING    -           -           -           STOPPING    STOPPING    DEAD
     *   DEAD        DEAD        -           DEAD        DEAD        -           DEAD
     *
     * Five entries look odd and are deliberate:
     *  - STARTING has no dispatch: that IS the feature. A worker that hasn't
     *    finished its bootstrap cannot be given work, and the table is what
     *    guarantees it rather than a check someone has to remember.
     *  - DRAINING + ready stays DRAINING, and DEAD + ready stays DEAD: a
     *    reload or a crash can overtake a READY already on the wire, and
     *    the late handshake must not revive a worker that is leaving.
     *  - DRAINING + respond stays DRAINING, so a worker that just answered
     *    its last request cannot be handed another before it retires.
     *  - STOPPING/DEAD + drain is a no-op rather than an error: draining
     *    something already on its way out is a step backwards, not a bug.
     *  - DEAD + respond is tolerated because the reaper can mark a worker
     *    dead between its response arriving and being processed.
     *
     * @var array<string, array<string, WorkerState>>
     */
    private const array TRANSITIONS = [
        'ready' => [
            'STARTING' => WorkerState::IDLE,
            'DRAINING' => WorkerState::DRAINING,
            'DEAD' => WorkerState::DEAD,
        ],
        'dispatch' => [
            'IDLE' => WorkerState::BUSY,
        ],
        'respond' => [
            'BUSY' => WorkerState::IDLE,
            'DRAINING' => WorkerState::DRAINING,
            'DEAD' => WorkerState::DEAD,
        ],
        'drain' => [
            'STARTING' => WorkerState::DRAINING,
            'IDLE' => WorkerState::DRAINING,
            'BUSY' => WorkerState::DRAINING,
            'DRAINING' => WorkerState::DRAINING,
            'STOPPING' => WorkerState::STOPPING,
            'DEAD' => WorkerState::DEAD,
        ],
        'stop' => [
            'STARTING' => WorkerState::STOPPING,
            'IDLE' => WorkerState::STOPPING,
            'BUSY' => WorkerState::STOPPING,
            'DRAINING' => WorkerState::STOPPING,
            'STOPPING' => WorkerState::STOPPING,
        ],
        'die' => [
            'STARTING' => WorkerState::DEAD,
            'IDLE' => WorkerState::DEAD,
            'BUSY' => WorkerState::DEAD,
            'DRAINING' => WorkerState::DEAD,
            'STOPPING' => WorkerState::DEAD,
            'DEAD' => WorkerState::DEAD,
        ],
    ];

    /** The two counters recycling decides on - see WorkerPool::recycleExhaustedWorkers(). */
    private int $handledRequests = 0;

    private ?float $startedAt = null;

    // When the current request was dispatched here, per the pool's clock.
    // What separates "this worker is busy" from "this worker is stuck".
    private ?float $requestStartedAt = null;

    // When this worker was first told to leave (drain or stop), per the
    // pool's clock. What separates "this worker is on its way out" from
    // "this worker is not going".
    private ?float $leavingSince = null;

    public function __construct(
        private readonly int $pid,
        private readonly Socket $socket,
        // STARTING, because that is what a freshly forked worker is. A test
        // double with no process behind it (see FakeWorkerLauncher) passes
        // IDLE instead: nothing is ever going to send its READY.
        private WorkerState $state = WorkerState::STARTING,
        private ?string $currentRequestId = null,
    ) {
    }

    private bool $terminating = false;

    /** How many requests this worker has completed since it was forked. */
    public function getHandledRequests(): int
    {
        return $this->handledRequests;
    }

    /**
     * Stamped by WorkerPool when it takes ownership, from the pool's own
     * Clock - deliberately not read here from a clock of this object's own,
     * which is how a WorkerProcess built by a test double ended up aged
     * against a different timeline than the pool judging it.
     */
    public function markLaunchedAt(float $now): void
    {
        $this->startedAt ??= $now;
    }

    /**
     * Seconds since the pool took ownership, per $now. An unstamped worker
     * reports age 0 - never old enough to recycle, which is the safe way to
     * be wrong.
     */
    public function getAgeSeconds(float $now): float
    {
        return $now - ($this->startedAt ?? $now);
    }

    /**
     * How long this worker has been on its way out, or null if it isn't
     * (or was drained/stopped without a timestamp, which only happens in
     * tests driving WorkerProcess directly) - null reads as "never
     * overstayed", the same safe-way-to-be-wrong getAgeSeconds() takes.
     *
     * Stamped once, on the FIRST drain() or stop(): a worker that drains
     * and is then stopped is one departure, and restarting the clock at
     * each step would let a worker that keeps being nudged along never look
     * overdue.
     */
    public function getLeavingSeconds(float $now): ?float
    {
        return $this->leavingSince === null ? null : $now - $this->leavingSince;
    }

    public function getPid(): int
    {
        return $this->pid;
    }

    public function getState(): WorkerState
    {
        return $this->state;
    }

    public function getCurrentRequestId(): ?string
    {
        return $this->currentRequestId;
    }

    /**
     * Whether the worker can currently accept a request or be stopped: it is
     * working on nothing and is not on its way out.
     */
    public function isAvailable(): bool
    {
        return $this->state === WorkerState::IDLE;
    }

    /**
     * The worker has finished its bootstrap and may be dispatched to.
     *
     * Called on the READY message and nowhere else: the Master has no way of
     * knowing when an application's warm-up finished, which is the whole
     * reason this state exists.
     */
    public function markReady(): void
    {
        $this->apply('ready');
    }

    /** Forked but not yet dispatchable - see markReady(). */
    public function isStarting(): bool
    {
        return $this->state === WorkerState::STARTING;
    }

    /** Whether the worker is working on a request right now, whatever its state. */
    public function isWorking(): bool
    {
        return $this->currentRequestId !== null;
    }

    /**
     * Marks the worker as busy with the given request, then returns
     * IDLE once the request has finished.
     */
    public function beginRequest(string $requestId, ?float $now = null): void
    {
        $this->apply('dispatch');

        $this->currentRequestId = $requestId;
        $this->requestStartedAt = $now;
    }

    /**
     * How long the current request has been running, or null if the worker
     * isn't working (or was dispatched to without a timestamp, which only
     * happens in tests driving WorkerProcess directly).
     */
    public function getWorkingSeconds(float $now): ?float
    {
        if ($this->currentRequestId === null || $this->requestStartedAt === null) {
            return null;
        }

        return $now - $this->requestStartedAt;
    }

    public function finishRequest(): void
    {
        // An async SIGCHLD reap can mark this worker DEAD between its
        // response being read and this call - crash detection got there
        // first (and already cleared the request), so there's nothing left
        // to finish. Tolerating it beats throwing from a timing window.
        if ($this->state === WorkerState::DEAD) {
            return;
        }

        // BUSY -> IDLE, DRAINING -> DRAINING: see the table.
        $this->apply('respond');

        $this->currentRequestId = null;
        $this->requestStartedAt = null;
        $this->handledRequests++;
    }

    /**
     * Takes the worker out of rotation without interrupting it: no new
     * request will be dispatched here, and one already in flight is left
     * completely alone - it finishes and its answer is routed back exactly
     * as it would have been. WorkerPool::retireIdleWorkers() is what
     * actually stops it, once isWorking() is false.
     *
     * The one mechanism behind graceful reload (a whole generation drained
     * at once), scale-down (a few idle workers), and recycling (one worker
     * that hit a limit).
     *
     * Idempotent, and a no-op once the worker is already on its way out -
     * draining something that is STOPPING or DEAD would be a step backwards.
     *
     * $now (the pool's clock, as everywhere else here) starts the departure
     * clock WorkerPool::terminateStuckWorkers() judges an overdue drain by.
     */
    public function drain(?float $now = null): void
    {
        $this->apply('drain');

        if ($now !== null) {
            $this->leavingSince ??= $now;
        }
    }

    public function isDraining(): bool
    {
        return $this->state === WorkerState::DRAINING;
    }

    /**
     * Requests shutdown. Legal from any state but DEAD — including BUSY,
     * since the pool must always be able to stop even if a request never
     * finished — abandoning any in-flight request.
     *
     * $now, as in drain(): a worker stopped without ever being drained is
     * still a worker whose departure has a deadline.
     */
    public function stop(?float $now = null): void
    {
        $this->apply('stop');

        if ($now !== null) {
            $this->leavingSince ??= $now;
        }

        $this->currentRequestId = null;
        $this->requestStartedAt = null;
    }

    public function markDead(): void
    {
        $this->apply('die');

        $this->currentRequestId = null;
        $this->requestStartedAt = null;
    }

    /**
     * Records that WE ended this worker rather than it dying on its own -
     * so the reaper counts it as a termination, not a crash. A rising crash
     * count means something is wrong with the workers; a rising termination
     * count means requests are exceeding their execution limit, which is a
     * different problem with a different fix.
     */
    public function markTerminating(): void
    {
        $this->terminating = true;
    }

    public function isTerminating(): bool
    {
        return $this->terminating;
    }

    /**
     * Applies $event per TRANSITIONS, or throws if this state has no answer
     * for it. The single gate every state change goes through - there is no
     * other assignment to $this->state in this class.
     */
    private function apply(string $event): void
    {
        $next = self::TRANSITIONS[$event][$this->state->name] ?? null;

        if ($next === null) {
            throw new LogicException(
                sprintf('Illegal transition: cannot %s a worker in state %s', $event, $this->state->name)
            );
        }

        $this->state = $next;
    }

    public function write(Message $message): void
    {
        $this->socket->write($message);
    }

    /** @return resource */
    public function getResource(): mixed
    {
        return $this->socket->getResource();
    }

    /** @return list<Message> */
    public function readAvailable(): array
    {
        return $this->socket->readAvailable();
    }

    public function close(): void
    {
        $this->socket->close();
    }
}
