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
        $this->assertSame(0, $snapshot->requestsPending);

        $pool->stop();
    }

    /**
     * Metrics::requestsAccountedFor() is a claim about five counters that
     * live in three components with no view of each other, and the snapshot
     * is the only place they meet. This walks one request through each of
     * the five outcomes, recording it exactly the way Master does, and
     * checks the partition after every single step - so a request that fell
     * out of all five buckets, or got counted into two, shows up here as
     * arithmetic rather than as a slow drift someone notices in production
     * six months later.
     */
    public function testEveryAcceptedRequestStaysAccountedForThroughEveryOutcome(): void
    {
        $pool = new WorkerPool(1, new FakeWorkerLauncher());
        $queue = new RequestQueue(1);
        $pendingRequests = new PendingRequestRegistry();
        $requestMetrics = new RequestMetrics();
        $collector = new MetricsCollector($pool, $queue, $pendingRequests, $requestMetrics);

        $socket = fopen('php://memory', 'r+');
        $this->assertNotFalse($socket);
        $client = new ClientConnection(new Socket($socket));

        $assertAccountedFor = function () use ($collector): void {
            $snapshot = $collector->snapshot();

            $this->assertSame(
                $snapshot->requestsTotal,
                $snapshot->requestsAccountedFor(),
                sprintf(
                    'total %d != completed %d + failed %d + timeout %d + rejected %d + pending %d',
                    $snapshot->requestsTotal,
                    $snapshot->requestsCompleted,
                    $snapshot->requestsFailed,
                    $snapshot->requestsTimeout,
                    $snapshot->requestsRejected,
                    $snapshot->requestsPending,
                )
            );
        };

        $assertAccountedFor(); // nothing has happened yet: 0 = 0

        // 1. Accepted and in flight - the term that makes the rest a
        //    partition rather than an inequality.
        $requestMetrics->recordReceived();
        $answered = $pendingRequests->register($client, 'client-1', 30.0);
        $assertAccountedFor();

        // 2. Answered (Master::routeResponse on a RESPONSE).
        $this->assertNotNull($pendingRequests->resolve($answered));
        $requestMetrics->recordCompleted();
        $assertAccountedFor();

        // 3. Failed - a worker died holding it (Master::reapCrashedWorkers).
        $requestMetrics->recordReceived();
        $crashed = $pendingRequests->register($client, 'client-2', 30.0);
        $this->assertNotNull($pendingRequests->resolve($crashed));
        $requestMetrics->recordFailed();
        $assertAccountedFor();

        // 4. Timed out (Master::sendTimeouts), which the registry counts
        //    itself rather than through RequestMetrics.
        $requestMetrics->recordReceived();
        $pendingRequests->register($client, 'client-3', -1.0);
        $this->assertCount(1, $pendingRequests->removeExpired(microtime(true)));
        $assertAccountedFor();

        // 5. Rejected: accepted, counted, then turned away by a full queue -
        //    and resolved back out of the registry, which is the step that
        //    would double-count it if Master ever skipped it.
        $queue->enqueue(new Message(MessageType::REQUEST, 'filler'));
        $requestMetrics->recordReceived();
        $rejected = $pendingRequests->register($client, 'client-4', 30.0);
        $this->assertTrue($queue->isFull());
        $queue->recordRejection();
        $this->assertNotNull($pendingRequests->resolve($rejected));
        $assertAccountedFor();

        // 6. Its client vanishes while one of its requests is in flight
        //    (Master::handleClientDisconnect).
        $requestMetrics->recordReceived();
        $pendingRequests->register($client, 'client-5', 30.0);
        $assertAccountedFor();

        foreach ($pendingRequests->removeByClient($client) as $orphaned) {
            $requestMetrics->recordFailed();
        }

        $assertAccountedFor();

        $snapshot = $collector->snapshot();
        $this->assertSame(5, $snapshot->requestsTotal);
        $this->assertSame(1, $snapshot->requestsCompleted);
        $this->assertSame(2, $snapshot->requestsFailed);
        $this->assertSame(1, $snapshot->requestsTimeout);
        $this->assertSame(1, $snapshot->requestsRejected);
        $this->assertSame(0, $snapshot->requestsPending);

        $pool->stop();
    }
}
