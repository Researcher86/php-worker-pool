<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\Logger;

/**
 * Logger that appends to a file, for assertions across a fork.
 *
 * A worker's log line is written in another process, so a logger collecting
 * into an array would collect it into that process's copy and the test would
 * never see it. A file is the cheapest thing both sides can reach - opened
 * per write, in append mode, so concurrent workers cannot lose each other's
 * lines.
 */
final readonly class FileLogger implements Logger
{
    public function __construct(private string $path)
    {
    }

    public function log(string $message): void
    {
        file_put_contents($this->path, $message . "\n", FILE_APPEND | LOCK_EX);
    }

    /** @return list<string> */
    public function lines(): array
    {
        $contents = (string) @file_get_contents($this->path);

        return array_values(array_filter(explode("\n", trim($contents)), strlen(...)));
    }
}
