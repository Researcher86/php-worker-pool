<?php

declare(strict_types=1);

namespace PhpWorkerPool\Support;

/** Default no-op logger, so logging stays optional for tests and library use. */
final readonly class NullLogger implements Logger
{
    public function log(string $message): void
    {
    }
}
