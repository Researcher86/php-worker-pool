<?php

declare(strict_types=1);

namespace App\Sdk;

use App\IPC\ConnectionClosedException;

/**
 * A request already on the wire whose answer hasn't been collected yet -
 * what WorkerPoolClient::send() hands back so several requests can be in
 * flight at once instead of one at a time:
 *
 *     $a = $client->send(new Request('calculate', ...));
 *     $b = $client->send(new Request('calculate', ...));
 *
 *     [$first, $second] = $client->all($a, $b);   // both already running
 *
 * Deliberately not a promise: nothing runs in the background, and no
 * callback ever fires. This is just a claim ticket - the work is happening
 * in the pool, and await() (or the client's all()) is where this process
 * blocks to collect it.
 *
 * One-shot: collecting the same handle twice throws, the same way resolving
 * a pending request twice does on the Master's side.
 */
final readonly class PendingResponse
{
    public function __construct(
        private WorkerPoolClient $client,
        /** The correlation id this request went out under. */
        public string $id,
    ) {
    }

    /**
     * Blocks until this request's answer arrives (or its timeout elapses),
     * collecting - and buffering - any other pending answers that show up
     * first.
     *
     * @return array<string, mixed> the response payload
     *
     * @throws RequestTimedOutException|ServerErrorException|ConnectionClosedException
     */
    public function await(): array
    {
        return $this->client->await($this);
    }
}
