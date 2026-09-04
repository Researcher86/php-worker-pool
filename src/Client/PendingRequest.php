<?php

declare(strict_types=1);

namespace App\Client;

/**
 * One entry in PendingRequestRegistry: which client is waiting for the
 * response to a request the Master dispatched, what id that client used on
 * the wire (so the response can be sent back under that id, not whatever
 * internal id the Master dispatched it under), and by when it must have
 * been answered (PHASES.md Phase 14 - microtime(true) seconds, matching
 * everywhere else in this codebase that measures elapsed time).
 *
 * Also carries the two timestamps a latency breakdown needs: when the
 * Master accepted the request, and when a worker actually picked it up.
 * The gap between them is queue time, the gap after is execution time, and
 * keeping them apart is what distinguishes "the pool is saturated" from
 * "the handler is slow" - one number for both answers neither.
 */
final class PendingRequest
{
    private ?float $dispatchedAt = null;

    public function __construct(
        public readonly ClientConnection $client,
        public readonly string $originalId,
        public readonly float $deadline,
        public readonly float $acceptedAt = 0.0,
    ) {
    }

    public function markDispatched(float $now): void
    {
        $this->dispatchedAt ??= $now;
    }

    /** Seconds spent waiting for a free worker, or null if never dispatched. */
    public function queuedSeconds(): ?float
    {
        return $this->dispatchedAt === null ? null : $this->dispatchedAt - $this->acceptedAt;
    }

    /** Seconds a worker spent on it, or null if it never got that far. */
    public function executionSeconds(float $now): ?float
    {
        return $this->dispatchedAt === null ? null : $now - $this->dispatchedAt;
    }
}
