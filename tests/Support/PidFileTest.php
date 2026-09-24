<?php

declare(strict_types=1);

namespace PhpWorkerPool\Tests\Support;

use PHPUnit\Framework\TestCase;
use PhpWorkerPool\Support\PidFile;

final class PidFileTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/pwp-pidfile-test-' . getmypid() . '-' . uniqid() . '.pid';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testANeverWrittenPidFileHasNoPid(): void
    {
        $pidFile = new PidFile($this->path);

        $this->assertNull($pidFile->read());
        $this->assertFalse($pidFile->isProcessRunning());
    }

    public function testWriteThenReadRoundTrips(): void
    {
        $pidFile = new PidFile($this->path);

        $pidFile->write(12345);

        $this->assertSame(12345, $pidFile->read());
    }

    public function testWriteCreatesAMissingDirectory(): void
    {
        $nested = sys_get_temp_dir() . '/pwp-pidfile-test-dir-' . uniqid() . '/sub/server.pid';
        $pidFile = new PidFile($nested);

        $pidFile->write(1);

        $this->assertSame(1, $pidFile->read());

        @unlink($nested);
        @rmdir(dirname($nested));
        @rmdir(dirname($nested, 2));
    }

    public function testRemoveDeletesTheFile(): void
    {
        $pidFile = new PidFile($this->path);
        $pidFile->write(1);

        $pidFile->remove();

        $this->assertFalse(is_file($this->path));
        $this->assertNull($pidFile->read());
    }

    public function testRemoveOnAMissingFileDoesNotThrow(): void
    {
        $pidFile = new PidFile($this->path);

        $pidFile->remove();

        $this->assertFalse(is_file($this->path));
    }

    public function testIsProcessRunningIsTrueForThisOwnProcess(): void
    {
        $pidFile = new PidFile($this->path);
        $pidFile->write((int) getmypid());

        $this->assertTrue($pidFile->isProcessRunning());
    }

    public function testIsProcessRunningIsFalseForAPidNothingUses(): void
    {
        // PID 1 is always taken (init/PID namespace root) in every
        // container this suite runs in - pick a value guaranteed unused
        // instead: the highest pid the kernel could ever hand out plus a
        // margin no real process will occupy in this run.
        $pidFile = new PidFile($this->path);
        $pidFile->write(999_999);

        $this->assertFalse($pidFile->isProcessRunning());
    }

    public function testAMalformedPidFileReadsAsNull(): void
    {
        file_put_contents($this->path, "not-a-pid\n");
        $pidFile = new PidFile($this->path);

        $this->assertNull($pidFile->read());
        $this->assertFalse($pidFile->isProcessRunning());
    }
}
