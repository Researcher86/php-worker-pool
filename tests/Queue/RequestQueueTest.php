<?php

declare(strict_types=1);

namespace App\Tests\Queue;

use App\Protocol\Message;
use App\Protocol\MessageType;
use App\Queue\RequestQueue;
use PHPUnit\Framework\TestCase;

final class RequestQueueTest extends TestCase
{
    private RequestQueue $queue;

    protected function setUp(): void
    {
        $this->queue = new RequestQueue();
    }

    public function testStartsEmpty(): void
    {
        $this->assertTrue($this->queue->isEmpty());
        $this->assertSame(0, $this->queue->size());
    }

    public function testEnqueueIncreasesSize(): void
    {
        $this->queue->enqueue(new Message(MessageType::REQUEST, 'req-1'));
        $this->queue->enqueue(new Message(MessageType::REQUEST, 'req-2'));

        $this->assertFalse($this->queue->isEmpty());
        $this->assertSame(2, $this->queue->size());
    }

    public function testDequeueReturnsInFifoOrder(): void
    {
        $this->queue->enqueue(new Message(MessageType::REQUEST, 'req-1'));
        $this->queue->enqueue(new Message(MessageType::REQUEST, 'req-2'));

        $this->assertSame('req-1', $this->queue->dequeue()->id);
        $this->assertSame('req-2', $this->queue->dequeue()->id);

        $this->assertTrue($this->queue->isEmpty());
    }
}