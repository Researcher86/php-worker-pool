<?php

declare(strict_types=1);

namespace App\Client;

/**
 * One entry in PendingRequestRegistry: which client is waiting for the
 * response to a request the Master dispatched, what id that client used on
 * the wire (so the response can be sent back under that id, not whatever
 * internal id the Master dispatched it under), and by when it must have
 * been answered (PLAN.md Phase 14 - microtime(true) seconds, matching
 * everywhere else in this codebase that measures elapsed time).
 */
final readonly class PendingRequest
{
    public function __construct(
        public ClientConnection $client,
        public string $originalId,
        public float $deadline,
    ) {
    }
}
