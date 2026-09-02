<?php

declare(strict_types=1);

namespace App\Queue;

use App\Protocol\Message;

/**
 * FIFO queue of requests awaiting dispatch to a free worker.
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
