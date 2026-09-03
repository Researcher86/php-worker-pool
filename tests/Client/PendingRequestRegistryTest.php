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

        $dispatchId = $registry->register($client, 'client-original-id');

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

        $dispatchId = $registry->register($client, 'req-1');
        $registry->resolve($dispatchId);

        $this->assertNull($registry->resolve($dispatchId));
    }

    public function testTwoClientsReusingTheSameOriginalIdDoNotCollide(): void
    {
        $registry = new PendingRequestRegistry();
        $clientA = $this->client();
        $clientB = $this->client();

        $idA = $registry->register($clientA, 'req-1');
        $idB = $registry->register($clientB, 'req-1');

        $this->assertNotSame($idA, $idB);

        $pendingA = $registry->resolve($idA);
        $pendingB = $registry->resolve($idB);

        $this->assertSame($clientA, $pendingA->client);
        $this->assertSame($clientB, $pendingB->client);
        $this->assertSame('req-1', $pendingA->originalId);
        $this->assertSame('req-1', $pendingB->originalId);
    }
}
