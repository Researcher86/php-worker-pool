<?php

declare(ticks = 1);

namespace App\Worker;

use App\Protocol\Message;
use App\Protocol\MessageType;

final class WorkerPool
{
    /** @var array<int, WorkerProcess> */
    private array $workers = [];

    /**
     * $launcher defaults to actually forking a process — pass a test double
     * to get workers backed by a plain socket pair instead, with no real
     * process involved (see WorkerLauncher).
     */
    public function __construct(int $workerCount, ?WorkerLauncher $launcher = new ForkedWorkerLauncher())
    {
        for ($i = 0; $i < $workerCount; $i++) {
            $worker = $launcher->launch();

            $this->workers[$worker->getPid()] = $worker;
        }
    }

    public function count(): int
    {
        return count($this->workers);
    }

    /**
     * Returns the id of any worker that can currently accept a request, or
     * null if all workers are busy, stopping, or dead.
     */
    public function getAvailable(): ?int
    {
        foreach ($this->workers as $id => $worker) {
            if ($worker->isAvailable()) {
                return $id;
            }
        }

        return null;
    }

    public function write(int $workerId, Message $message): WorkerProcess
    {
        $worker = $this->workers[$workerId];

        $worker->write($message);
        $worker->beginRequest($message->id);

        return $worker;
    }

    public function stop(): void
    {
        // Pass 1: tell every still-connected worker to shut down and close
        // our end of its socket. A worker already DEAD (its process is gone,
        // see Dispatcher/ConnectionClosedException) skips the shutdown
        // message — there's nothing left to send it to.
        foreach ($this->workers as $worker) {
            if ($worker->getState() !== WorkerState::DEAD) {
                $worker->stop();
                $worker->write(new Message(MessageType::SHUTDOWN, 'shutdown-' . $worker->getPid()));
            }

            $worker->close();
        }

        // pcntl_waitpid(-1, ...) reaps whichever child exits next, not a
        // specific pid, so there's no way to know which WorkerProcess it
        // belonged to. Reap everyone first, then mark every worker DEAD in
        // a second pass below — by the time we get there all of them really
        // are gone.
        while (pcntl_waitpid(-1, $status) !== -1) {
            // reap all children
        }

        foreach ($this->workers as $worker) {
            if ($worker->getState() !== WorkerState::DEAD) {
                $worker->markDead();
            }
        }
    }
}
