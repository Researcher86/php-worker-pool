<?php

declare(strict_types=1);

namespace App\Tests\Worker\Telemetry;

use App\Worker\Telemetry\SharedTelemetry;
use App\Worker\Telemetry\TelemetrySlot;
use PHPUnit\Framework\TestCase;

final class SharedTelemetryTest extends TestCase
{
    /** @var list<SharedTelemetry> */
    private array $opened = [];

    /** @var list<string> */
    private array $anchors = [];

    protected function tearDown(): void
    {
        foreach ($this->opened as $telemetry) {
            $telemetry->destroy();
        }

        foreach ($this->anchors as $anchor) {
            @unlink($anchor);
        }

        $this->opened = [];
        $this->anchors = [];
    }

    public function testPublishedReadingRoundTrips(): void
    {
        $telemetry = $this->open(4);
        $slot = $this->reserveFor($telemetry, posix_getpid());

        $slot->publish(12_345_678, 1_700_000_000.5);

        $vitals = $telemetry->read(posix_getpid());

        $this->assertNotNull($vitals);
        $this->assertSame(posix_getpid(), $vitals->pid);
        $this->assertSame(12_345_678, $vitals->memoryBytes);
        $this->assertSame(1_700_000_000.5, $vitals->updatedAt);
    }

    public function testLatestPublishWins(): void
    {
        $telemetry = $this->open(2);
        $slot = $this->reserveFor($telemetry, posix_getpid());

        $slot->publish(1_000, 1.0);
        $slot->publish(2_000, 2.0);
        $slot->publish(3_000, 3.0);

        $this->assertSame(3_000, $telemetry->read(posix_getpid())?->memoryBytes);
    }

    public function testReservedSlotReportsNothingUntilItsWorkerPublishes(): void
    {
        $telemetry = $this->open(2);
        $this->reserveFor($telemetry, posix_getpid());

        // Reserved and bound, but the worker hasn't written anything yet -
        // which the recycling policy must see as "unmeasurable", not as a
        // worker using zero bytes.
        $this->assertNull($telemetry->read(posix_getpid()));
    }

    public function testUnknownPidReportsNothing(): void
    {
        $telemetry = $this->open(2);

        $this->assertNull($telemetry->read(999_999));
    }

    public function testSlotOfADeadWorkerIsReusedAndItsOldReadingIsNotServedToItsSuccessor(): void
    {
        $telemetry = $this->open(1); // exactly one slot, so reuse is forced

        $deadPid = $this->forkAndReap();
        $first = $telemetry->reserve();
        $this->assertNotNull($first);
        $telemetry->bind($first, $deadPid);
        $first->publish(999_999, 1.0);

        $second = $telemetry->reserve();

        $this->assertNotNull($second, 'the dead worker\'s slot should have been reusable');
        $this->assertSame($first->index, $second->index);

        $telemetry->bind($second, posix_getpid());

        // Same slot, still holding the dead worker's bytes - but they belong
        // to a pid that isn't the one being asked about.
        $this->assertNull($telemetry->read(posix_getpid()));
    }

    public function testSlotOfALivingWorkerIsNotHandedOut(): void
    {
        $telemetry = $this->open(1);
        $this->reserveFor($telemetry, posix_getpid());

        $this->assertNull($telemetry->reserve(), 'the only slot belongs to a living process');
    }

    public function testReservedButUnboundSlotIsNotHandedOutTwice(): void
    {
        $telemetry = $this->open(1);

        $this->assertNotNull($telemetry->reserve());
        // A slot between reserve() and the fork that will own it has no pid
        // yet - and must not look free because of it.
        $this->assertNull($telemetry->reserve());
    }

    public function testReleasedSlotBecomesAvailableAgain(): void
    {
        $telemetry = $this->open(1);
        $slot = $telemetry->reserve();
        $this->assertNotNull($slot);

        $telemetry->release($slot); // the fork it was meant for never happened

        $this->assertNotNull($telemetry->reserve());
    }

    public function testWorkerPublishesAcrossAForkAndTheParentReadsIt(): void
    {
        $telemetry = $this->open(2);
        $slot = $telemetry->reserve();
        $this->assertNotNull($slot);

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'fork failed');

        if ($pid === 0) {
            // The segment is attached before the fork, so the child inherits
            // the mapping itself - this is what lets a worker publish with
            // nothing sent to it after it was forked.
            $slot->publish(4_242_424, 7.5);

            exit(0);
        }

        $telemetry->bind($slot, $pid);
        pcntl_waitpid($pid, $status);

        $vitals = $telemetry->read($pid);

        $this->assertNotNull($vitals, 'the parent should see what the child published');
        $this->assertSame($pid, $vitals->pid);
        $this->assertSame(4_242_424, $vitals->memoryBytes);
        $this->assertSame(7.5, $vitals->updatedAt);
    }

    public function testEachWorkerLandsInItsOwnSlot(): void
    {
        $telemetry = $this->open(4);
        $pids = [];

        // Two real children, because a slot always publishes the pid of the
        // process doing the publishing - one process cannot stand in for two
        // workers here, and shouldn't be able to.
        foreach ([111_000, 222_000] as $bytes) {
            $slot = $telemetry->reserve();
            $this->assertNotNull($slot);

            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'fork failed');

            if ($pid === 0) {
                $slot->publish($bytes, 1.0);

                exit(0);
            }

            $telemetry->bind($slot, $pid);
            $pids[$bytes] = $pid;
        }

        // Reaped only after both are forked: a reaped worker's slot is
        // immediately reusable, so reaping inside the loop above would hand
        // the second child the first one's slot - correct behaviour, but not
        // what this test is about.
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $this->assertSame(111_000, $telemetry->read($pids[111_000])?->memoryBytes);
        $this->assertSame(222_000, $telemetry->read($pids[222_000])?->memoryBytes);
    }

    public function testDestroyRemovesBothTheSegmentAndItsAnchor(): void
    {
        $anchor = $this->anchorPath();

        $telemetry = SharedTelemetry::openAt($anchor, 2);
        $this->assertFileExists($anchor);

        $telemetry->destroy();

        // Nothing left behind on a clean shutdown: no segment for the anchor
        // to point at means no reason to keep the anchor.
        $this->assertFileDoesNotExist($anchor);
    }

    public function testAnOrphanedSegmentIsReplacedRatherThanInherited(): void
    {
        $anchor = $this->anchorPath();

        $abandoned = SharedTelemetry::openAt($anchor, 2);
        $slot = $abandoned->reserve();
        $this->assertNotNull($slot);
        $abandoned->bind($slot, posix_getpid());
        $slot->publish(555, 1.0);
        // No destroy(): exactly what a SIGKILLed Master leaves behind.

        $successor = SharedTelemetry::openAt($anchor, 2);
        $this->opened[] = $successor;

        $reused = $successor->reserve();
        $this->assertNotNull($reused);
        $successor->bind($reused, posix_getpid());

        $this->assertNull(
            $successor->read(posix_getpid()),
            'the successor must start from a clean segment, not from the orphan\'s contents',
        );
    }

    public function testSeparateAnchorsDoNotShareASegment(): void
    {
        $first = $this->open(2);
        $second = $this->open(2);

        $slot = $this->reserveFor($first, posix_getpid());
        $slot->publish(777, 1.0);

        $this->assertNull($second->read(posix_getpid()));
    }

    private function open(int $slots): SharedTelemetry
    {
        $telemetry = SharedTelemetry::openAt($this->anchorPath(), $slots);
        $this->opened[] = $telemetry;

        return $telemetry;
    }

    private function anchorPath(): string
    {
        $anchor = sys_get_temp_dir() . '/telemetry-test-' . posix_getpid() . '-' . count($this->anchors) . '.anchor';
        $this->anchors[] = $anchor;

        return $anchor;
    }

    private function reserveFor(SharedTelemetry $telemetry, int $pid): TelemetrySlot
    {
        $slot = $telemetry->reserve();

        $this->assertNotNull($slot);
        $telemetry->bind($slot, $pid);

        return $slot;
    }

    /** A pid that is genuinely gone - reaped, so not even a zombie posix_kill() would call alive. */
    private function forkAndReap(): int
    {
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'fork failed');

        if ($pid === 0) {
            exit(0);
        }

        pcntl_waitpid($pid, $status);

        return $pid;
    }
}
