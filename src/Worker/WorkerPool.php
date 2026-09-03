<?php

declare(ticks = 1);

namespace App\Worker;

use App\Protocol\Message;
use App\Protocol\MessageType;

final class WorkerPool
{
    /** @var array<int, WorkerProcess> */
    private array $workers = [];

    // Guards reapDeadWorkers() replacing a crashed worker while stop() is
    // in progress — spawning a fresh one mid-shutdown would just orphan it
    // (stop() only tells the workers present when it started to shut down).
    private bool $accepting = true;

    /**
     * $launcher defaults to actually forking a process — pass a test double
     * to get workers backed by a plain socket pair instead, with no real
     * process involved (see WorkerLauncher).
     */
    public function __construct(
        int $workerCount,
        private readonly WorkerLauncher $launcher = new ForkedWorkerLauncher(),
    ) {
        for ($i = 0; $i < $workerCount; $i++) {
            $worker = $this->launcher->launch();

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

    /**
     * Reaps any worker process that has exited since the last call — never
     * blocks (WNOHANG), so this is safe to call from a SIGCHLD handler.
     * Each crashed worker is removed from the pool and, unless the pool is
     * shutting down, immediately replaced so the pool stays at its
     * configured size.
     *
     * @return list<WorkerCrash>
     */
    public function reapDeadWorkers(): array
    {
        $crashes = [];

        while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
            $worker = $this->workers[$pid] ?? null;

            if ($worker === null) {
                continue; // not a worker we're tracking (already handled elsewhere)
            }

            // Capture before markDead() clears it - Dispatcher may
            // independently detect and report the same crash via the
            // worker's closed socket; whichever of the two gets here first
            // is the one that actually has a non-null id to report.
            $lostRequestId = $worker->getCurrentRequestId();
            $worker->markDead();
            unset($this->workers[$pid]);

            $crashes[] = new WorkerCrash($worker, $lostRequestId);

            if ($this->accepting) {
                $replacement = $this->launcher->launch();
                $this->workers[$replacement->getPid()] = $replacement;
            }
        }

        return $crashes;
    }

    public function stop(): void
    {
        $this->accepting = false;

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
