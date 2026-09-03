<?php

declare(strict_types=1);

namespace App\Tests\Worker;

use App\IPC\Socket;
use App\Worker\WorkerLauncher;
use App\Worker\WorkerProcess;

/**
 * Test double for WorkerLauncher: delegates to a FakeWorkerLauncher for
 * every call except the $failOnCall'th one, which throws - then recovers
 * on every call after that. Simulates a transient launch failure (e.g. a
 * momentary fork() failure under resource pressure) that clears up on
 * retry, unlike FailingWorkerLauncher's permanent failure.
 */
final class FlakyOnceWorkerLauncher implements WorkerLauncher
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

        if ($this->calls === $this->failOnCall) {
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
