<?php

declare(strict_types=1);

namespace App\Client;

/**
 * One entry in PendingRequestRegistry: which client is waiting for the
 * response to a request the Master dispatched, and what id that client
 * used on the wire (so the response can be sent back under that id, not
 * whatever internal id the Master dispatched it under).
 */
final readonly class PendingRequest
{
    public function __construct(
        public ClientConnection $client,
        public string $originalId,
    ) {
    }
}
