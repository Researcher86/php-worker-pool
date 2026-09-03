<?php

declare(strict_types=1);

namespace App\Tests\Worker;

use App\Worker\ForkedWorkerLauncher;
use App\Worker\WorkerLauncher;
use App\Worker\WorkerProcess;

/**
 * Test double for WorkerLauncher: delegates to a real ForkedWorkerLauncher
 * (actually forking a process) except for the $failOnCall'th call, which
 * throws instead - lets a test exercise a real crash-and-replace cycle
 * where one specific replacement launch fails.
 */
final class FlakyForkedWorkerLauncher implements WorkerLauncher
{
    private readonly ForkedWorkerLauncher $delegate;

    private int $calls = 0;

    public function __construct(private readonly int $failOnCall)
    {
        $this->delegate = new ForkedWorkerLauncher();
    }

    public function launch(): WorkerProcess
    {
        $this->calls++;

        if ($this->calls === $this->failOnCall) {
            throw new \RuntimeException('launch failed');
        }

        return $this->delegate->launch();
    }
}
