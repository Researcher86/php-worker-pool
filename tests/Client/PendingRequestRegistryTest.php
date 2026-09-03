<?php

declare(strict_types=1);

namespace App\Tests\Client;

use App\Client\ClientConnection;
use App\Client\PendingRequestRegistry;
use App\IPC\Socket;
use App\Tests\Support\FakeClock;
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
        $clock = new FakeClock(1_000.0);
        $registry = new PendingRequestRegistry($clock);

        $registry->register($this->client(), 'expired', 30.0); // deadline 1030
        $freshClient = $this->client();
        $registry->register($freshClient, 'still-fresh', 100.0); // deadline 1100

        $clock->set(1_030.0);
        $expired = $registry->removeExpired($clock->now());

        $this->assertCount(1, $expired);
        $this->assertSame('expired', $expired[0]->originalId);
        $this->assertSame(1, $registry->count()); // the fresh one is still tracked
        $this->assertSame(1, $registry->timeoutCount());
    }

    public function testRemoveExpiredIsOneShotAndAccumulatesTimeoutCount(): void
    {
        $clock = new FakeClock();
        $registry = new PendingRequestRegistry($clock);

        $id = $registry->register($this->client(), 'req-1', 30.0);
        $clock->advance(30.0);

        $this->assertCount(1, $registry->removeExpired($clock->now()));
        $this->assertCount(0, $registry->removeExpired($clock->now())); // already removed
        $this->assertNull($registry->resolve($id)); // and not resolvable either

        $this->assertSame(1, $registry->timeoutCount());
    }

    public function testDrainAllReturnsAndRemovesEverythingRegardlessOfDeadline(): void
    {
        $clock = new FakeClock();
        $registry = new PendingRequestRegistry($clock);
        $clientA = $this->client();
        $clientB = $this->client();

        // One already past its deadline, one far from it - drainAll()
        // doesn't care either way, unlike removeExpired().
        $registry->register($clientA, 'req-a', -1.0);
        $registry->register($clientB, 'req-b', 100.0);

        $drained = $registry->drainAll();

        $this->assertCount(2, $drained);
        $ids = array_map(static fn ($p) => $p->originalId, $drained);
        sort($ids);
        $this->assertSame(['req-a', 'req-b'], $ids);

        $this->assertSame(0, $registry->count());
    }

    /**
     * Regression coverage: without this, a client's in-flight request just
     * sat until its timeout deadline after the client disconnected, even
     * though there was no longer anyone to ever deliver the response to.
     */
    public function testRemoveByClientRemovesOnlyThatClientsEntriesRegardlessOfDeadline(): void
    {
        $registry = new PendingRequestRegistry();
        $gone = $this->client();
        $stillHere = $this->client();

        $registry->register($gone, 'from-gone-1', 30.0);
        $goneId2 = $registry->register($gone, 'from-gone-2', -1.0); // already past deadline - shouldn't matter
        $stillHereId = $registry->register($stillHere, 'from-still-here', 30.0);

        $orphaned = $registry->removeByClient($gone);

        $this->assertCount(2, $orphaned);
        $originalIds = array_map(static fn ($p) => $p->originalId, $orphaned);
        sort($originalIds);
        $this->assertSame(['from-gone-1', 'from-gone-2'], $originalIds);

        // The other client's entry, and only that one, is still tracked.
        $this->assertSame(1, $registry->count());
        $this->assertNotNull($registry->resolve($stillHereId));

        // Already removed by removeByClient() - not resolvable, and not
        // returned again by removeExpired() despite its deadline.
        $this->assertNull($registry->resolve($goneId2));
        $this->assertCount(0, $registry->removeExpired(PHP_FLOAT_MAX));
    }

    public function testRemoveByClientReturnsEmptyWhenThatClientHasNoPendingEntries(): void
    {
        $registry = new PendingRequestRegistry();
        $registry->register($this->client(), 'someone-elses', 30.0);

        $this->assertSame([], $registry->removeByClient($this->client()));
        $this->assertSame(1, $registry->count());
    }
}
