<?php

declare(strict_types=1);

namespace PhpWorkerPool\Support;

/**
 * The same timestamped line StderrLogger writes, to a real file instead -
 * for any application whose Master runs somewhere StderrLogger's output
 * would be lost or unreadable: backgrounded under a process manager with
 * its own log-capture path, run from a context with no attached terminal,
 * or simply wanting a durable file a metrics dashboard or a log shipper can
 * follow instead of console output.
 *
 * Opened per write, in append mode with an exclusive lock: this Logger
 * reaches every forked worker too (it is what the bootstrap hook and the
 * application's own handler log through), so several processes can be
 * writing to the same file at once, and a write without the lock could
 * interleave two workers' lines into one another mid-write.
 */
final readonly class FileLogger implements Logger
{
    public function __construct(
        private string $path,
    ) {
    }

    public function log(string $message): void
    {
        $line = sprintf('[%s] %s%s', date('Y-m-d H:i:s'), $message, PHP_EOL);

        @file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
    }
}
