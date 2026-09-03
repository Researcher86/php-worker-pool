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

    /**
     * PLAN.md Phase 16's safety timeout: sends every worker SHUTDOWN, waits
     * up to $timeoutSeconds for them to actually exit, then SIGKILLs
     * whatever is still alive rather than blocking forever on a worker
     * that's stuck or simply never got the message. Callers that already
     * drained pending work first (see Master) will normally find every
     * worker exits well within the timeout - it exists for the case where
     * that didn't happen.
     */
    public function stop(float $timeoutSeconds = 5.0): void
    {
        $this->accepting = false;

        // Pass 1: tell every still-connected worker to shut down and close
        // our end of its socket. A worker already DEAD (its process is gone,
        // see Dispatcher/ConnectionClosedException) skips the shutdown
        // message — there's nothing left to send it to.
        $awaiting = 0;

        foreach ($this->workers as $worker) {
            if ($worker->getState() !== WorkerState::DEAD) {
                $worker->stop();
                $worker->write(new Message(MessageType::SHUTDOWN, 'shutdown-' . $worker->getPid()));
                $awaiting++;
            }

            $worker->close();
        }

        // pcntl_waitpid(-1, ...) reaps whichever child exits next, not a
        // specific pid, so there's no way to know which WorkerProcess it
        // belonged to - $awaiting just counts however many are still out
        // there, regardless of which. No sleep between WNOHANG polls would
        // busy-spin the CPU for the whole timeout whenever a worker takes
        // any real time to exit, so back off slightly between attempts.
        $deadline = microtime(true) + $timeoutSeconds;

        while ($awaiting > 0 && microtime(true) < $deadline) {
            $pid = pcntl_waitpid(-1, $status, WNOHANG);

            if ($pid > 0) {
                $awaiting--;
            } elseif ($pid === -1) {
                // No child processes exist at all (errno ECHILD) - nothing
                // left to wait for. Real workers that already exited take
                // this path too, but it's also what a WorkerLauncher test
                // double with no real process behind it looks like from the
                // very first check - without this, $awaiting would never
                // reach 0 and this loop would just spin for the full
                // timeout every time.
                break;
            } else {
                usleep(10_000);
            }
        }

        // Anything still alive past the timeout is stuck (or just never
        // read the SHUTDOWN message) - force it. SIGKILL can't be caught,
        // blocked, or ignored, so every remaining worker is guaranteed to
        // actually exit almost immediately, bounding the final reap below
        // even though that wait isn't itself time-limited.
        foreach ($this->workers as $worker) {
            if ($worker->getState() !== WorkerState::DEAD) {
                posix_kill($worker->getPid(), SIGKILL);
            }
        }

        while (pcntl_waitpid(-1, $status) !== -1) {
            // reap whatever's left
        }

        foreach ($this->workers as $worker) {
            if ($worker->getState() !== WorkerState::DEAD) {
                $worker->markDead();
            }
        }
    }
}
