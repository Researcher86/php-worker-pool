<?php

declare(strict_types=1);

namespace App\Tests\Client;

use App\Client\ClientConnection;
use App\Client\PendingRequestRegistry;
use App\IPC\Socket;
use PHPUnit\Framework\TestCase;

final class PendingRequestRegistryTest extends TestCase
{
    private function client(): ClientConnection
    {
        $socket = fopen('php://memory', 'r+');
        $this->assertNotFalse($socket);

        return new ClientConnection(new Socket($socket));
    }

    public function testRegisterAssignsAFreshIdAndResolveReturnsTheClientBack(): void
    {
        $registry = new PendingRequestRegistry();
        $client = $this->client();

        $dispatchId = $registry->register($client, 'client-original-id', 30.0);

        $this->assertSame(1, $registry->count());

        $pending = $registry->resolve($dispatchId);

        $this->assertNotNull($pending);
        $this->assertSame($client, $pending->client);
        $this->assertSame('client-original-id', $pending->originalId);
        $this->assertSame(0, $registry->count());
    }

    public function testResolveUnknownIdReturnsNull(): void
    {
        $registry = new PendingRequestRegistry();

        $this->assertNull($registry->resolve('nope'));
    }

    public function testResolveIsOneShot(): void
    {
        $registry = new PendingRequestRegistry();
        $client = $this->client();

        $dispatchId = $registry->register($client, 'req-1', 30.0);
        $registry->resolve($dispatchId);

        $this->assertNull($registry->resolve($dispatchId));
    }

    public function testTwoClientsReusingTheSameOriginalIdDoNotCollide(): void
    {
        $registry = new PendingRequestRegistry();
        $clientA = $this->client();
        $clientB = $this->client();

        $idA = $registry->register($clientA, 'req-1', 30.0);
        $idB = $registry->register($clientB, 'req-1', 30.0);

        $this->assertNotSame($idA, $idB);

        $pendingA = $registry->resolve($idA);
        $pendingB = $registry->resolve($idB);

        $this->assertSame($clientA, $pendingA->client);
        $this->assertSame($clientB, $pendingB->client);
        $this->assertSame('req-1', $pendingA->originalId);
        $this->assertSame('req-1', $pendingB->originalId);
    }

    public function testRemoveExpiredReturnsAndRemovesOnlyEntriesPastTheirDeadline(): void
    {
        $registry = new PendingRequestRegistry();

        // register() computes its deadline from the real clock (microtime())
        // rather than accepting an injectable "now", so a negative timeout
        // is the deterministic way to get an already-past deadline without
        // sleeping in the test: deadline = now + (-1) is before any "now"
        // checked immediately after.
        $registry->register($this->client(), 'expired', -1.0);
        $freshClient = $this->client();
        $registry->register($freshClient, 'still-fresh', 100.0);

        $expired = $registry->removeExpired(microtime(true));

        $this->assertCount(1, $expired);
        $this->assertSame('expired', $expired[0]->originalId);
        $this->assertSame(1, $registry->count()); // the fresh one is still tracked
        $this->assertSame(1, $registry->timeoutCount());
    }

    public function testRemoveExpiredIsOneShotAndAccumulatesTimeoutCount(): void
    {
        $registry = new PendingRequestRegistry();

        $id = $registry->register($this->client(), 'req-1', -1.0);

        $this->assertCount(1, $registry->removeExpired(microtime(true)));
        $this->assertCount(0, $registry->removeExpired(microtime(true))); // already removed
        $this->assertNull($registry->resolve($id)); // and not resolvable either

        $this->assertSame(1, $registry->timeoutCount());
    }
}
