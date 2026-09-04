<?php

declare(strict_types=1);

namespace App\Client;

use App\Support\Clock;
use App\Support\SystemClock;

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

    public function __construct(
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    /**
     * Registers $client as awaiting a response and returns the id to
     * dispatch the request under. $timeoutSeconds from now, the entry
     * becomes eligible for removeExpired() to reclaim.
     */
    public function register(ClientConnection $client, string $originalId, float $timeoutSeconds): string
    {
        $id = 'req-' . $this->nextId++;
        $now = $this->clock->now();
        $this->pending[$id] = new PendingRequest($client, $originalId, $now + $timeoutSeconds, $now);

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

    /**
     * Removes and returns every still-tracked entry, regardless of
     * deadline. For graceful shutdown (PLAN.md Phase 16): whatever is left
     * once the drain window closes needs to be told the Master is going
     * away, not just silently abandoned.
     *
     * @return list<PendingRequest>
     */
    public function drainAll(): array
    {
        $all = array_values($this->pending);
        $this->pending = [];

        return $all;
    }

    /**
     * Removes and returns every entry waiting on $client, regardless of
     * deadline - a client can disconnect (or send a malformed message,
     * ClientRegistry treats both the same) while one of its requests is
     * still in flight. Without this, that entry would just sit until its
     * timeout deadline: the response it's waiting for, once a worker
     * produces it, would resolve() into a client whose socket is already
     * closed - wasted work for nothing anyone can ever receive.
     *
     * @return list<PendingRequest>
     */
    public function removeByClient(ClientConnection $client): array
    {
        $orphaned = [];

        foreach ($this->pending as $id => $entry) {
            if ($entry->client === $client) {
                $orphaned[] = $entry;
                unset($this->pending[$id]);
            }
        }

        return $orphaned;
    }

    /**
     * Marks when a worker actually picked $id up - the boundary between
     * queue time and execution time. Unknown ids are ignored: a request can
     * be resolved (timed out, its client gone) between being dispatched and
     * this arriving.
     */
    public function markDispatched(string $id, float $now): void
    {
        ($this->pending[$id] ?? null)?->markDispatched($now);
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
