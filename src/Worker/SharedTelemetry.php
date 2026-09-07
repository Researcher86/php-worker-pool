<?php

declare(strict_types=1);

namespace App\Worker;

use RuntimeException;
use Shmop;

/**
 * A fixed table of per-worker slots in one shared-memory segment: workers
 * publish what only they can measure about themselves, the Master reads it
 * whenever it wants to.
 *
 * WHY SHARED MEMORY AND NOT A MESSAGE. This is state, not an event. The
 * Master already polls it once per tick on its own schedule, a reading that
 * arrives late is simply superseded by the next one, and a lost one costs
 * nothing - so paying for it in IPC messages would put steady traffic on the
 * request channel to deliver something no one is waiting for. Shared memory
 * gives the same numbers for the price of a memcpy, and - the part that
 * matters - it stays entirely out of the event loop: no fd, but also nothing
 * that ever needs to WAKE anybody, which is exactly the property that makes
 * a pull-only side channel the right shape here and makes a SysV message
 * queue the wrong one for the request path (docs/DECISIONS.md compares them).
 *
 * LAYOUT. `$slots` fixed-size records, no allocator, so a worker's write is
 * an offset computed from its index and nothing in the segment can be
 * corrupted by a worker that dies mid-write:
 *
 *     +0   seq        u32   even = settled, odd = a write is in progress
 *     +4   (padding)  u32
 *     +8   pid        u64   who published this reading
 *     +16  memory     u64   memory_get_usage(true) in that worker
 *     +24  updatedAt  f64   wall clock, the one both processes share
 *
 * SEQLOCK. A slot has exactly one writer, so writes never race each other -
 * but a reader can still catch a write half-applied, and a torn memory
 * figure would recycle a perfectly healthy worker. The writer bumps seq to
 * odd, writes the body, bumps it to even; a reader that sees an odd seq, or
 * a different one after the body than before it, discards the reading rather
 * than trusting it. Discarding is free here: the next tick reads again.
 *
 * SLOT REUSE. Slots are reserved by the Master (the only process that
 * reserves, so no locking) and never explicitly freed - reserve() reuses any
 * slot whose worker is no longer alive. A reused slot still holds the dead
 * worker's numbers, which is why every reading carries the pid that wrote it
 * and read() refuses one that doesn't match who was asked about.
 *
 * KEY AND ORPHANS. The key comes from ftok() over a small anchor file next
 * to the Master's socket, so a Master that was SIGKILLed - a segment is
 * kernel-persistent and outlives the process that made it - leaves behind
 * something its successor can FIND and remove, which a random key could not.
 * The anchor outlives the Master exactly as long as the segment might: it is
 * removed only once the segment really is gone (see destroy()), because
 * ftok() hashes the inode and deleting it any earlier would hand the next
 * Master a different key and strand the orphan for good.
 */
final class SharedTelemetry
{
    private const int SLOT_BYTES = 32;
    private const int BODY_OFFSET = 8;
    private const int BODY_BYTES = 24;
    private const int SEQ_BYTES = 4;

    // A reader that loses the seqlock race retries rather than reporting
    // nothing: the writer holds it for three small writes, so losing twice
    // in a row means something else is going on and the tick can move along.
    private const int READ_ATTEMPTS = 3;

    /**
     * index => pid of the worker it belongs to, or null while it is reserved
     * but its fork hasn't happened yet. Master-side bookkeeping only - the
     * copy a worker inherits through fork() is never consulted there.
     *
     * @var array<int, int|null>
     */
    private array $owners = [];

    private function __construct(
        private readonly Shmop $segment,
        private readonly int $slots,
        private readonly string $keyPath,
    ) {
    }

    /**
     * Opens (or recreates) the segment anchored at $keyPath.
     *
     * Throws rather than degrading: shared memory is a hard requirement
     * (`ext-shmop` in composer.json), so a failure here is a broken
     * deployment - an unwritable anchor path, a segment left by another
     * user, a kernel out of segments - and one that a Master silently
     * running without worker readings would only turn into a memory limit
     * that mysteriously never fires.
     */
    public static function openAt(string $keyPath, int $slots): self
    {
        if (!file_exists($keyPath) && !touch($keyPath)) {
            throw new RuntimeException('telemetry: cannot create the anchor file at ' . $keyPath);
        }

        $key = ftok($keyPath, 't');

        if ($key === -1) {
            throw new RuntimeException('telemetry: ftok failed for ' . $keyPath);
        }

        // An orphan from a Master that never got to destroy() its segment.
        // Remove it instead of attaching: it may have been sized for a
        // different maxWorkers, and its contents describe processes that
        // have been gone for who knows how long. The handle has to go out of
        // scope for the removal to take effect - the kernel only drops a
        // segment marked for deletion once nothing is attached to it.
        $orphan = @shmop_open($key, 'w', 0, 0);

        if ($orphan instanceof Shmop) {
            @shmop_delete($orphan);
            unset($orphan);
        }

        $segment = shmop_open($key, 'c', 0600, $slots * self::SLOT_BYTES);

        if ($segment === false) {
            throw new RuntimeException('telemetry: cannot open a shared memory segment for ' . $keyPath);
        }

        return new self($segment, $slots, $keyPath);
    }

    /**
     * Takes a slot for a worker about to be forked, or null when every slot
     * is spoken for by a living worker (a pool that has grown past the size
     * the segment was made for). A caller that gets null simply runs that
     * worker without telemetry.
     */
    public function reserve(): ?TelemetrySlot
    {
        for ($index = 0; $index < $this->slots; $index++) {
            // array_key_exists, not ??: a reserved-but-not-yet-forked slot
            // is stored as null, and ?? would read that as "no entry" and
            // hand the same slot out twice.
            if (array_key_exists($index, $this->owners)) {
                $owner = $this->owners[$index];

                // Still reserved for a fork that hasn't returned, or owned
                // by a worker that is still running: either way, not ours.
                if ($owner === null || $this->isAlive($owner)) {
                    continue;
                }
            }

            $this->owners[$index] = null;
            // Zero the slot: until its new owner publishes, seq is 0 and
            // read() reports nothing - rather than the previous worker's
            // last reading, which would be attributed to the new pid.
            shmop_write($this->segment, str_repeat("\0", self::SLOT_BYTES), $index * self::SLOT_BYTES);

            return new TelemetrySlot($this, $index);
        }

        return null;
    }

    /** Records which worker a reserved slot ended up belonging to, once its fork returned a pid. */
    public function bind(TelemetrySlot $slot, int $pid): void
    {
        $this->owners[$slot->index] = $pid;
    }

    /** Hands a reserved slot back when the fork it was meant for never happened. */
    public function release(TelemetrySlot $slot): void
    {
        unset($this->owners[$slot->index]);
    }

    /** The latest reading $pid published, or null if there isn't a trustworthy one. */
    public function read(int $pid): ?WorkerVitals
    {
        $index = array_search($pid, $this->owners, true);

        if ($index === false) {
            return null;
        }

        $base = $index * self::SLOT_BYTES;

        for ($attempt = 0; $attempt < self::READ_ATTEMPTS; $attempt++) {
            $before = $this->readSeq($base);

            // Odd: a write is in flight. Zero: nothing has ever been
            // published into this slot.
            if ($before === 0 || $before % 2 !== 0) {
                continue;
            }

            $body = unpack('Ppid/Pmemory/eupdatedAt', shmop_read($this->segment, $base + self::BODY_OFFSET, self::BODY_BYTES));

            if ($this->readSeq($base) !== $before) {
                continue; // the body moved under us - try again
            }

            // A slot reused by a new worker whose first publish hasn't
            // landed yet, or bookkeeping that drifted: either way this
            // reading isn't about the worker that was asked for.
            if ($body['pid'] !== $pid) {
                return null;
            }

            return new WorkerVitals($pid, $body['memory'], $body['updatedAt']);
        }

        return null;
    }

    /**
     * Publishes one worker's reading. Called only through that worker's own
     * TelemetrySlot, from inside the worker process.
     *
     * @internal
     */
    public function write(int $index, int $pid, int $memoryBytes, float $now): void
    {
        $base = $index * self::SLOT_BYTES;
        $seq = $this->readSeq($base);

        // Always land on odd, whatever we started from: a seq left odd by a
        // worker killed mid-write must not be "finished" by the next one.
        $begin = ($seq % 2 === 0 ? $seq + 1 : $seq + 2) & 0xFFFFFFFF;

        shmop_write($this->segment, pack('V', $begin), $base);
        shmop_write($this->segment, pack('PPe', $pid, $memoryBytes, $now), $base + self::BODY_OFFSET);
        shmop_write($this->segment, pack('V', ($begin + 1) & 0xFFFFFFFF), $base);
    }

    /**
     * Hands the segment back to the kernel, and only then drops the anchor
     * that named it - in that order, and not at all if the removal failed:
     * an anchor is worth keeping precisely as long as there might still be a
     * segment for a later Master to find through it.
     */
    public function destroy(): void
    {
        if (@shmop_delete($this->segment)) {
            @unlink($this->keyPath);
        }
    }

    private function readSeq(int $base): int
    {
        /** @var array{1: int} $seq */
        $seq = unpack('V', shmop_read($this->segment, $base, self::SEQ_BYTES));

        return $seq[1];
    }

    private function isAlive(int $pid): bool
    {
        return posix_kill($pid, 0);
    }
}
