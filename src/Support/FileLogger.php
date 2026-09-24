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
 *
 * That open-and-lock-every-time makes it the right Logger for discrete
 * lifecycle events (warm-up, recycling, shutdown) and the wrong one for a
 * per-request hot path - O(syscalls) per line will not stay cheap under
 * request-rate logging, which wants a buffering writer instead.
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

        if (@file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX) === false) {
            // The file can't be written (permissions, disk full, a deleted
            // parent directory). Do not let the line vanish into the `@`:
            // fall back to the single channel every PHP process has.
            error_log('php-worker-pool FileLogger: could not write to ' . $this->path . ': ' . trim($line));
        }
    }
}
