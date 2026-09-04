<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Timestamped lines to STDERR - kept apart from STDOUT, which the SIGUSR1
 * metrics dump (see Master) already uses as its own output channel.
 */
final class StderrLogger implements Logger
{
    public function log(string $message): void
    {
        fwrite(STDERR, sprintf('[%s] %s%s', date('Y-m-d H:i:s'), $message, PHP_EOL));
    }
}
