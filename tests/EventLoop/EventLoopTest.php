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

    /**
     * Writable interest (used by ClientConnection's buffered writes): a
     * socket with room in its send buffer triggers the handler; once
     * removed, it never fires again.
     */
    public function testTickInvokesWritableHandlerUntilRemoved(): void
    {
        [$a, $b] = $this->pair();

        $invoked = 0;
        $this->loop->addWritable($a, function () use (&$invoked, $a): void {
            $invoked++;
            $this->loop->removeWritable($a);
        });

        $this->loop->tick(0.5); // a fresh socket is immediately writable
        $this->assertSame(1, $invoked);

        $this->loop->tick(0.1); // nothing registered anymore - returns without invoking
        $this->assertSame(1, $invoked);

        fclose($a);
        fclose($b);
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

    /**
     * The mechanism Master (Phase 14) relies on to notice expired requests
     * even with no client/worker activity at all: tick() must actually
     * return once $timeoutSeconds elapses, not block indefinitely.
     */
    public function testTickWithTimeoutReturnsWhenNothingBecomesReadable(): void
    {
        // Keep $writeEnd alive (even though nothing is written to it): if it
        // were garbage collected, its side of the pair would close, and the
        // read end would see that as an immediate EOF - readable right
        // away, defeating the point of this test.
        [$readEnd, $writeEnd] = $this->pair();

        $invoked = false;
        $this->loop->addReadable($readEnd, function () use (&$invoked): void {
            $invoked = true;
        });

        $start = microtime(true);
        $this->loop->tick(0.2); // nobody writes anything - this must time out
        $elapsed = microtime(true) - $start;

        $this->assertFalse($invoked);
        $this->assertGreaterThanOrEqual(0.2, $elapsed);
        $this->assertLessThan(2.0, $elapsed); // generous upper bound, just proving it didn't block forever
    }
}
