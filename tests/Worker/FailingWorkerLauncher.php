<?php

declare(strict_types=1);

namespace App\Tests\Worker;

use App\IPC\Socket;
use App\Worker\WorkerLauncher;
use App\Worker\WorkerProcess;

/**
 * Test double for WorkerLauncher: delegates to a FakeWorkerLauncher for the
 * first ($failOnCall - 1) calls, then throws on the $failOnCall'th one and
 * every call after - simulates a WorkerPool constructor that gets partway
 * through launching its configured worker count before pcntl_fork() fails.
 */
final class FailingWorkerLauncher implements WorkerLauncher
{
    private readonly FakeWorkerLauncher $delegate;

    private int $calls = 0;

    public function __construct(private readonly int $failOnCall)
    {
        $this->delegate = new FakeWorkerLauncher();
    }

    public function launch(): WorkerProcess
    {
        $this->calls++;

        if ($this->calls >= $this->failOnCall) {
            throw new \RuntimeException('launch failed');
        }

        return $this->delegate->launch();
    }

    /** @return list<Socket> the worker-side end of every pair successfully created so far */
    public function workerEnds(): array
    {
        return $this->delegate->workerEnds();
    }
}
