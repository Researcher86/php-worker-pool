<?php

declare(strict_types=1);

namespace App\Queue;

use App\Protocol\Message;

/**
 * FIFO queue of requests awaiting dispatch to a free worker.
 *
 * A thin wrapper over SplQueue rather than using it directly: SplQueue holds
 * mixed values, this holds only Message — giving callers a typed queue
 * without them having to know the underlying implementation is an SplQueue.
 *
 * $maxSize is the backpressure limit (PHASES.md Phase 13): with more workers
 * than the queue can ever hold requests for, an unbounded queue under
 * sustained overload just grows until the process runs out of memory.
 * Rejection itself happens one layer up, in Dispatcher::dispatch() — this
 * class only tracks the limit and how many requests were turned away
 * because of it.
 */
final class RequestQueue
{
    /** @var \SplQueue<Message> */
    private \SplQueue $queue;

    private int $rejectedCount = 0;

    /** @param int|null $maxSize null means unbounded */
    public function __construct(
        private readonly ?int $maxSize = null,
    ) {
        $this->queue = new \SplQueue();
    }

    public function enqueue(Message $message): void
    {
        $this->queue->enqueue($message);
    }

    public function dequeue(): Message
    {
        return $this->queue->dequeue();
    }

    public function size(): int
    {
        return $this->queue->count();
    }

    public function isEmpty(): bool
    {
        return $this->queue->isEmpty();
    }

    public function isFull(): bool
    {
        return $this->maxSize !== null && $this->size() >= $this->maxSize;
    }

    public function recordRejection(): void
    {
        $this->rejectedCount++;
    }

    public function rejectedCount(): int
    {
        return $this->rejectedCount;
    }
}
