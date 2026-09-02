<?php

declare(strict_types=1);

namespace App\Tests\EventLoop;

use App\EventLoop\EventLoop;
use PHPUnit\Framework\TestCase;

final class EventLoopTest extends TestCase
{
    private EventLoop $loop;

    protected function setUp(): void
    {
        $this->loop = new EventLoop();
    }

    /** @return array{0: resource, 1: resource} */
    private function pair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);

        return $pair;
    }

    public function testHasReadableReflectsRegistrations(): void
    {
        $this->assertFalse($this->loop->hasReadable());

        [$a, $b] = $this->pair();
        $this->loop->addReadable($a, static function (): void {
        });

        $this->assertTrue($this->loop->hasReadable());

        $this->loop->removeReadable($a);
        $this->assertFalse($this->loop->hasReadable());
    }

    public function testTickDoesNothingWhenNothingRegistered(): void
    {
        $invoked = false;

        $this->loop->tick(); // must return immediately, not block

        $this->assertFalse($invoked);
    }

    public function testTickInvokesHandlerWhenResourceBecomesReadable(): void
    {
        [$readEnd, $writeEnd] = $this->pair();

        $received = null;
        $this->loop->addReadable($readEnd, function () use ($readEnd, &$received): void {
            $received = fread($readEnd, 8192);
        });

        fwrite($writeEnd, 'hello');
        $this->loop->tick();

        $this->assertSame('hello', $received);
    }

    public function testTickInvokesEveryHandlerReadyInTheSameTick(): void
    {
        [$readA, $writeA] = $this->pair();
        [$readB, $writeB] = $this->pair();

        $calledFor = [];
        $this->loop->addReadable($readA, function () use (&$calledFor): void {
            $calledFor[] = 'a';
        });
        $this->loop->addReadable($readB, function () use (&$calledFor): void {
            $calledFor[] = 'b';
        });

        fwrite($writeA, 'x');
        fwrite($writeB, 'y');
        $this->loop->tick();

        sort($calledFor);
        $this->assertSame(['a', 'b'], $calledFor);
    }

    public function testRemoveReadableStopsInvokingHandler(): void
    {
        [$readA, $writeA] = $this->pair();
        [$readB, $writeB] = $this->pair();

        $calledFor = [];
        $this->loop->addReadable($readA, function () use (&$calledFor): void {
            $calledFor[] = 'a';
        });
        $this->loop->addReadable($readB, function () use (&$calledFor): void {
            $calledFor[] = 'b';
        });

        $this->loop->removeReadable($readA);

        fwrite($writeA, 'x');
        fwrite($writeB, 'y');
        $this->loop->tick();

        $this->assertSame(['b'], $calledFor);
    }
}
