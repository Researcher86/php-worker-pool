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
 * Deliberately holds just id => (client, original id) for now. PLAN.md's
 * Phase 11 sketch also lists Created Time / Worker / Deadline per entry,
 * but nothing in this phase's Definition of Done needs them - they belong
 * to later phases (Timeouts, Metrics). Add them when a phase actually
 * needs them, not before.
 */
final class PendingRequestRegistry
{
    private int $nextId = 1;

    /** @var array<string, PendingRequest> */
    private array $pending = [];

    /** Registers $client as awaiting a response and returns the id to dispatch the request under. */
    public function register(ClientConnection $client, string $originalId): string
    {
        $id = 'req-' . $this->nextId++;
        $this->pending[$id] = new PendingRequest($client, $originalId);

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

    public function count(): int
    {
        return count($this->pending);
    }
}
