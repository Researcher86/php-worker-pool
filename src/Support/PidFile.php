<?php

declare(strict_types=1);

namespace PhpWorkerPool\Support;

/**
 * What a Master's own pid-file convention writes, and a caller's `stop`/
 * `status` script reads back - the same contract php-mini-database's own
 * PidFile keeps, ported rather than reinvented since the two problems are
 * identical: "is this pid alive" and "refuse to start a second one over the
 * same thing" are both plain OS operations that need nothing from either
 * server's own protocol.
 *
 * A caller managing a Master this way never has to talk to it over the wire
 * to answer either question - there is nothing to connect FOR:
 * `posix_kill($pid, 0)` already answers "alive", and stopping it is a
 * signal, not a request.
 */
final readonly class PidFile
{
    public function __construct(
        private string $path,
    ) {
    }

    public function write(int $pid): void
    {
        $directory = dirname($this->path);

        if (!is_dir($directory)) {
            // 0755, not 0777: the file names a process, and a directory
            // world-writable on top of that is more than a pid file needs.
            @mkdir($directory, 0o755, true);
        }

        // Atomic against a concurrent reader: write to a temp file in the
        // same directory (so the rename stays on one filesystem) and rename
        // over the real path, rather than truncate-and-write, which a
        // reader could catch mid-write as an empty or partial pid.
        $tmp = $this->path . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, $pid . "\n");
        rename($tmp, $this->path);
    }

    public function read(): ?int
    {
        if (!is_file($this->path)) {
            return null;
        }

        $contents = trim((string) @file_get_contents($this->path));

        return ctype_digit($contents) ? (int) $contents : null;
    }

    public function remove(): void
    {
        @unlink($this->path);
    }

    /**
     * Whether the pid this file names is a live process -
     * `posix_kill($pid, 0)` sends no actual signal, only asks the kernel
     * whether it could. Genuinely answers differently between two calls in
     * the same request - a caller polling this in a loop while waiting for
     * a process to actually exit is not a bug, it is the entire point.
     *
     * A process it may signal is plainly alive; a pid nobody owns is plainly
     * not (ESRCH). The one case that is neither: EPERM - the process EXISTS
     * but belongs to another user, so the kernel refuses even to ask. That
     * is "running", just not ours to manage, and reporting it as stopped
     * would tell a caller the port is free when it is not.
     */
    public function isProcessRunning(): bool
    {
        $pid = $this->read();

        if ($pid === null) {
            return false;
        }

        if (!function_exists('posix_kill')) {
            return false;
        }

        if (@posix_kill($pid, 0)) {
            return true;
        }

        // 1 is EPERM on both Linux and macOS/Darwin - PHP defines no
        // errno constants, so the value stands literal next to its comment.
        return posix_get_last_error() === 1;
    }
}
