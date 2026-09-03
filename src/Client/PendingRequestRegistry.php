<?php

declare(strict_types=1);

namespace App\Client;

/**
 * Tracks which client is waiting for the response to which dispatched
 * request, so a worker's response - which can arrive in any order relative
 * to when requests were submitted - gets routed back to the right client.
 *
 * register() assigns the request its own id rather than trusting whatever
 * id the client sent: two different clients (or one client, by mistake)
 * could otherwise pick the same id, and the Master is the one place that
 * would silently misroute a response if that happened. The client never
 * sees this internal id - resolve() hands back its original one so the
 * response can go out under the id the client is actually expecting.
 *
 * Deliberately holds just id => (client, original id, deadline) - PLAN.md's
 * Phase 11 sketch also lists Worker per entry, but nothing so far needs it
 * (WorkerProcess already knows its own current request id). Add it when
 * something actually needs it, not before.
 */
final class PendingRequestRegistry
{
    private int $nextId = 1;

    private int $timeoutCount = 0;

    /** @var array<string, PendingRequest> */
    private array $pending = [];

    /**
     * Registers $client as awaiting a response and returns the id to
     * dispatch the request under. $timeoutSeconds from now, the entry
     * becomes eligible for removeExpired() to reclaim.
     */
    public function register(ClientConnection $client, string $originalId, float $timeoutSeconds): string
    {
        $id = 'req-' . $this->nextId++;
        $this->pending[$id] = new PendingRequest($client, $originalId, microtime(true) + $timeoutSeconds);

        return $id;
    }

    /**
     * Removes and returns the pending entry for $id, or null if none is
     * tracked (e.g. unknown id, or already resolved).
     */
    public function resolve(string $id): ?PendingRequest
    {
        $entry = $this->pending[$id] ?? null;
        unset($this->pending[$id]);

        return $entry;
    }

    /**
     * Removes and returns every entry whose deadline is at or before $now,
     * counting each as a timeout. A plain scan over every pending entry -
     * PLAN.md's own "Future Improvement" note for this phase says a timer
     * heap belongs here eventually, once scanning stops being cheap enough.
     *
     * @return list<PendingRequest>
     */
    public function removeExpired(float $now): array
    {
        $expired = [];

        foreach ($this->pending as $id => $entry) {
            if ($entry->deadline <= $now) {
                $expired[] = $entry;
                unset($this->pending[$id]);
            }
        }

        $this->timeoutCount += count($expired);

        return $expired;
    }

    public function count(): int
    {
        return count($this->pending);
    }

    public function timeoutCount(): int
    {
        return $this->timeoutCount;
    }
}
