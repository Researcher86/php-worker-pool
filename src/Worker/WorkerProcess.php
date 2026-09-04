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

    /** The two counters recycling decides on - see WorkerPool::recycleExhaustedWorkers(). */
    private int $handledRequests = 0;

    private ?float $startedAt = null;

    public function __construct(
        private readonly int $pid,
        private readonly Socket $socket,
        private WorkerState $state = WorkerState::STARTING,
        private ?string $currentRequestId = null,
    ) {
    }

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
    public function beginRequest(string $requestId): void
    {
        $this->assertTransitions(self::AVAILABLE_STATES);

        $this->currentRequestId = $requestId;
        $this->state = WorkerState::BUSY;
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

        $this->assertTransitions([WorkerState::BUSY, WorkerState::DRAINING]);

        $this->currentRequestId = null;
        $this->handledRequests++;

        // A worker drained mid-request stays DRAINING: it just answered its
        // last request and is now free to be retired, not free to take
        // another one.
        if ($this->state === WorkerState::BUSY) {
            $this->state = WorkerState::IDLE;
        }
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
        if ($this->state === WorkerState::STOPPING || $this->state === WorkerState::DEAD) {
            return;
        }

        $this->state = WorkerState::DRAINING;
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
        $this->assertTransitions([
            WorkerState::STARTING,
            WorkerState::IDLE,
            WorkerState::BUSY,
            WorkerState::DRAINING,
            WorkerState::STOPPING,
        ]);

        $this->currentRequestId = null;
        $this->state = WorkerState::STOPPING;
    }

    public function markDead(): void
    {
        $this->state = WorkerState::DEAD;
        $this->currentRequestId = null;
    }

    /** @param list<WorkerState> $allowed */
    private function assertTransitions(array $allowed): void
    {
        if (!in_array($this->state, $allowed, true)) {
            throw new \LogicException(
                sprintf('Illegal transition from state %s', $this->state->name)
            );
        }
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
