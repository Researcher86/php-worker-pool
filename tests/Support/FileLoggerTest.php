<?php

declare(strict_types=1);

namespace PhpWorkerPool\Tests\Support;

use PHPUnit\Framework\TestCase;
use PhpWorkerPool\Support\FileLogger;

final class FileLoggerTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/pwp-filelogger-test-' . getmypid() . '-' . uniqid() . '.log';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testWritesATimestampedLineToTheFile(): void
    {
        $logger = new FileLogger($this->path);

        $logger->log('worker 1: ready');

        $contents = (string) file_get_contents($this->path);
        $this->assertStringContainsString('worker 1: ready', $contents);
        $this->assertMatchesRegularExpression('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] worker 1: ready\n$/', $contents);
    }

    public function testAppendsRatherThanOverwriting(): void
    {
        $logger = new FileLogger($this->path);

        $logger->log('first');
        $logger->log('second');

        $contents = (string) file_get_contents($this->path);
        $this->assertStringContainsString('first', $contents);
        $this->assertStringContainsString('second', $contents);
        $this->assertLessThan(strpos($contents, 'second'), strpos($contents, 'first'));
    }

    public function testAnUnwritableLogFallsBackToErrorLogInsteadOfDroppingTheLine(): void
    {
        $fallback = sys_get_temp_dir() . '/pwp-filelogger-fallback-' . getmypid() . '-' . uniqid() . '.log';
        ini_set('error_log', $fallback);

        try {
            // A path that cannot be opened for writing: a file's own name
            // used as a directory. file_put_contents fails, and the line
            // must land in the error_log fallback rather than vanish.
            $logger = new FileLogger(sys_get_temp_dir() . '/pwp-not-a-directory-' . getmypid() . '-.log/out.log');
            $logger->log('worker 1: ready');

            $contents = (string) file_get_contents($fallback);
            $this->assertStringContainsString('could not write to', $contents);
            $this->assertStringContainsString('worker 1: ready', $contents);
        } finally {
            ini_restore('error_log');
            @unlink($fallback);
        }
    }

    public function testConcurrentWritersDoNotInterleaveALine(): void
    {
        // The reason for LOCK_EX: fork a handful of children that each
        // write one long line at the same time, and check every line came
        // through whole - never half of one child's message glued to
        // half of another's.
        $logger = new FileLogger($this->path);
        $pids = [];

        for ($i = 0; $i < 5; $i++) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                $logger->log(str_repeat((string) $i, 2000));
                exit(0);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->path)), strlen(...)));
        $this->assertCount(5, $lines);

        foreach ($lines as $line) {
            // Each line is a timestamp prefix plus one child's own 2000
            // identical digits - a torn write would show two different
            // digits mixed into one line.
            $this->assertMatchesRegularExpression('/^\[.+\] (\d)\1{1999}$/', $line);
        }
    }
}
