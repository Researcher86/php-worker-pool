<?php

declare(strict_types=1);

namespace App\Tests\Metrics;

use App\Client\ClientConnection;
use App\Client\PendingRequestRegistry;
use App\IPC\Socket;
use App\Metrics\MetricsCollector;
use App\Metrics\RequestMetrics;
use App\Protocol\Message;
use App\Protocol\MessageType;
use App\Queue\RequestQueue;
use App\Tests\Worker\FakeWorkerLauncher;
use App\Worker\WorkerPool;
use PHPUnit\Framework\TestCase;

final class MetricsCollectorTest extends TestCase
{
    public function testSnapshotAggregatesFromEachComponent(): void
    {
        $launcher = new FakeWorkerLauncher();
        $pool = new WorkerPool(2, $launcher);
        $queue = new RequestQueue(5);
        $pendingRequests = new PendingRequestRegistry();
        $requestMetrics = new RequestMetrics();
        $collector = new MetricsCollector($pool, $queue, $pendingRequests, $requestMetrics);

        // One worker busy, one idle.
        $busyWorkerId = $pool->getAvailable();
        $this->assertNotNull($busyWorkerId);
        $pool->write($busyWorkerId, new Message(MessageType::REQUEST, 'req-1'));

        // A request sitting in the queue (nothing to dispatch it to right now).
        $queue->enqueue(new Message(MessageType::REQUEST, 'req-2'));

        $requestMetrics->recordReceived();
        $requestMetrics->recordReceived();
        $requestMetrics->recordCompleted();

        $socket = fopen('php://memory', 'r+');
        $this->assertNotFalse($socket);
        $pendingRequests->register(new ClientConnection(new Socket($socket)), 'req-3', -1.0);
        $this->assertCount(1, $pendingRequests->removeExpired(microtime(true))); // -> requests_timeout: 1

        $queue->enqueue(new Message(MessageType::REQUEST, 'overflow-1'));
        $queue->enqueue(new Message(MessageType::REQUEST, 'overflow-2'));
        $queue->enqueue(new Message(MessageType::REQUEST, 'overflow-3'));
        $queue->enqueue(new Message(MessageType::REQUEST, 'overflow-4'));
        $queue->recordRejection();

        $snapshot = $collector->snapshot();

        $this->assertSame(2, $snapshot->workersTotal);
        $this->assertSame(1, $snapshot->workersIdle);
        $this->assertSame(1, $snapshot->workersBusy);
        $this->assertSame(0, $snapshot->workersCrashedTotal);
        $this->assertSame($queue->size(), $snapshot->queueSize);
        $this->assertSame(2, $snapshot->requestsTotal);
        $this->assertSame(1, $snapshot->requestsCompleted);
        $this->assertSame(0, $snapshot->requestsFailed);
        $this->assertSame(1, $snapshot->requestsTimeout);
        $this->assertSame(1, $snapshot->requestsRejected);

        $pool->stop();
    }
}
