<?php

declare(strict_types=1);

namespace App\Tests\Worker;

use App\IPC\SocketPair;
use App\Protocol\Message;
use App\Protocol\MessageType;
use App\Worker\ForkedWorkerLauncher;
use App\Worker\SharedTelemetry;
use App\Worker\ShmWorkerMemory;
use PHPUnit\Framework\TestCase;

final class ShmWorkerMemoryTest extends TestCase
{
    private SharedTelemetry $telemetry;

    private string $anchor = '';

    protected function setUp(): void
    {
        $this->anchor = sys_get_temp_dir() . '/shm-memory-test-' . posix_getpid() . '.anchor';
        $this->telemetry = SharedTelemetry::openAt($this->anchor, 4);
    }

    protected function tearDown(): void
    {
        $this->telemetry->destroy();
        @unlink($this->anchor);
    }

    public function testReportsWhatTheWorkerPublished(): void
    {
        $telemetry = $this->telemetry;
        $slot = $telemetry->reserve();
        $this->assertNotNull($slot);

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'fork failed');

        if ($pid === 0) {
            $slot->publish(64 * 1024 * 1024, microtime(true));

            exit(0);
        }

        $telemetry->bind($slot, $pid);
        pcntl_waitpid($pid, $status);

        $this->assertSame(64 * 1024 * 1024, (new ShmWorkerMemory($telemetry))->measure($pid));
    }

    public function testUnmeasurableWorkerReportsNullRatherThanZero(): void
    {
        $telemetry = $this->telemetry;

        // A worker nobody has heard from is exactly what RecyclingPolicy
        // must not treat as "using 0 bytes" - it leaves the memory limit
        // unenforced instead.
        $this->assertNull((new ShmWorkerMemory($telemetry))->measure(999_999));
    }

    public function testAForkedWorkerPublishesItsOwnMemoryWhileServingRequests(): void
    {
        $telemetry = $this->telemetry;
        $launcher = new ForkedWorkerLauncher(null, $telemetry);
        $worker = $launcher->launch();
        $memory = new ShmWorkerMemory($telemetry);

        try {
            // The baseline WorkerRunner publishes before its first read.
            $baseline = $this->waitForReading($memory, $worker->getPid());

            $this->assertNotNull($baseline, 'a forked worker should publish before it starts serving');
            $this->assertGreaterThan(0, $baseline, 'a live PHP process cannot have allocated nothing');

            $worker->write(new Message(MessageType::REQUEST, 'req-1', ['ping' => 'pong']));

            $answer = null;

            // The READY handshake is on this socket too, ahead of the answer.
            while ($answer === null) {
                foreach ($worker->readAvailable() as $message) {
                    if ($message->type !== MessageType::READY) {
                        $answer = $message;
                    }
                }
            }

            $this->assertSame('req-1', $answer->id);
            // Whatever the number moved to, it is still a real reading from
            // that process - the point being that it keeps arriving without
            // anything being asked of the worker over the socket.
            $this->assertNotNull($memory->measure($worker->getPid()));
        } finally {
            $worker->write(new Message(MessageType::SHUTDOWN, 'stop'));
            pcntl_waitpid($worker->getPid(), $status);
        }
    }

    private function waitForReading(ShmWorkerMemory $memory, int $pid, float $timeout = 5.0): ?int
    {
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            $reading = $memory->measure($pid);

            if ($reading !== null) {
                return $reading;
            }

            usleep(1_000);
        }

        return null;
    }
}
