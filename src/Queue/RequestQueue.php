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
 */
final class RequestQueue
{
    /** @var \SplQueue<Message> */
    private \SplQueue $queue;

    public function __construct()
    {
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
}
