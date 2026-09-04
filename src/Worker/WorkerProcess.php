<?php

declare(strict_types=1);

namespace App\Worker;

use App\IPC\Socket;
use App\Protocol\Message;

/**
 * Master-side handle for one worker process: its pid, its socket, its
 * lifecycle state, and the counters recycling decides on.
 *
 * State machine (see WorkerState):
 *
 *   STARTING ──beginRequest()──> BUSY ──finishRequest()──> IDLE
 *      │                          │  ↑____________________/
 *      │                          │        beginRequest()
 *      │                          │
 *      │        drain()           │        drain()
 *      └────────────┬─────────────┴────────────┐
 *                   ▼                          ▼
 *               DRAINING ◀──finishRequest()──DRAINING
 *               (no work)                   (still working)
 *                   │
 *                   │  stop()          (also legal from any state but DEAD,
 *                   ▼                   so shutdown is never blocked)
 *               STOPPING
 *                   │
 *                   ▼
 *   any state ──markDead()──> DEAD (terminal, no way out)
 *
 * Two things are tracked separately on purpose: the STATE says whether new
 * work may be dispatched here, and $currentRequestId says whether work is
 * happening right now. DRAINING is the combination the old design needed a
 * second map for - "no new requests, but leave whatever it's doing alone".
 * A worker drained while BUSY keeps its request id until it answers.
 *
 * "Available" (isAvailable()) means STARTING or IDLE - the two states from
 * which a new request may be dispatched.
 */
final class WorkerProcess
{
    private const array AVAILABLE_STATES = [WorkerState::STARTING, WorkerState::IDLE];

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
     *   FROM        dispatch    respond     drain       stop        die
     *   ─────────────────────────────────────────────────────────────────
     *   STARTING    BUSY        -           DRAINING    STOPPING    DEAD
     *   IDLE        BUSY        -           DRAINING    STOPPING    DEAD
     *   BUSY        -           IDLE        DRAINING    STOPPING    DEAD
     *   DRAINING    -           DRAINING    DRAINING    STOPPING    DEAD
     *   STOPPING    -           -           STOPPING    STOPPING    DEAD
     *   DEAD        -           DEAD        DEAD        -           DEAD
     *
     * Three entries look odd and are deliberate:
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
        'dispatch' => [
            'STARTING' => WorkerState::BUSY,
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

    public function __construct(
        private readonly int $pid,
        private readonly Socket $socket,
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
     * Whether the worker can currently accept a request or be stopped
     * (it has not been dispatched to, or is done with its last request).
     */
    public function isAvailable(): bool
    {
        return in_array($this->state, self::AVAILABLE_STATES, true);
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
     */
    public function drain(): void
    {
        $this->apply('drain');
    }

    public function isDraining(): bool
    {
        return $this->state === WorkerState::DRAINING;
    }

    /**
     * Requests shutdown. Legal from any state but DEAD — including BUSY,
     * since the pool must always be able to stop even if a request never
     * finished — abandoning any in-flight request.
     */
    public function stop(): void
    {
        $this->apply('stop');

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
            throw new \LogicException(
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
