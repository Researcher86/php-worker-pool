<?php

declare(strict_types=1);

namespace App\Tests\Worker;

use App\Worker\WorkerLauncher;
use App\Worker\WorkerProcess;
use RuntimeException;

/**
 * Test double for WorkerLauncher: wraps any other WorkerLauncher (a fake
 * one for a fork-free test, or a real ForkedWorkerLauncher for one that
 * needs actual processes) and makes its $failOnCall'th call throw instead
 * of delegating - simulates a transient launch failure (e.g. pcntl_fork()
 * failing under resource pressure). $permanent controls whether every call
 * from $failOnCall onward keeps failing (a launcher that never recovers)
 * or only that one call does (a launcher that recovers on retry).
 */
final class FlakyWorkerLauncher implements WorkerLauncher
{
    private int $calls = 0;

    public function __construct(
        private readonly WorkerLauncher $delegate,
        private readonly int $failOnCall,
        private readonly bool $permanent = false,
    ) {
    }

    public function launch(): WorkerProcess
    {
        $this->calls++;

        $shouldFail = $this->permanent
            ? $this->calls >= $this->failOnCall
            : $this->calls === $this->failOnCall;

        if ($shouldFail) {
            throw new RuntimeException('launch failed');
        }

        return $this->delegate->launch();
    }
}
