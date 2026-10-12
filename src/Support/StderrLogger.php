<?php

declare(strict_types=1);

namespace PhpWorkerPool\Support;

/**
 * Timestamped lines to STDERR - kept apart from STDOUT, which the SIGUSR1
 * metrics dump (see Master) already uses as its own output channel.
 *
 * The write blocks when whatever reads STDERR stops reading (a full pipe,
 * a stalled log collector), and with it the single-threaded Master - signal
 * handlers included, since PHP runs them between statements, not inside a
 * blocked fwrite(). Kept blocking on purpose: making STDERR non-blocking
 * changes the file description it shares with every other process on the
 * same terminal or pipe, and turns back-pressure into silently dropped log
 * lines. Whoever starts the Master owns draining its STDERR - or hands it a
 * FileLogger instead.
 */
final readonly class StderrLogger implements Logger
{
    public function log(string $message): void
    {
        fwrite(STDERR, sprintf('[%s] %s%s', date('Y-m-d H:i:s'), $message, PHP_EOL));
    }
}
