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

    // PLAN.md Phase 17: how many workers have crashed over the pool's whole
    // lifetime. A live count of currently-dead workers would be close to
    // meaningless here - reapDeadWorkers() removes and replaces each one
    // essentially immediately, so that number is almost always 0.
    private int $totalCrashed = 0;

    // PLAN.md Phase 19: pids of the outgoing generation during a reload().
    // Still present in $workers (so stop()/reapDeadWorkers() keep working
    // unchanged) but excluded from getAvailable() - a busy one is left
    // completely alone until it finishes on its own, a set-and-forget flag
    // rather than a second array to keep in sync.
    /** @var array<int, true> */
    private array $retiringPids = [];

    // PLAN.md Phase 19/20 interaction: outgoing pids from reload() still
    // waiting for a replacement because launching one right away would push
    // the pool past $maxWorkers (relevant once Autoscaler can have grown it
    // close to that ceiling already). Drained by advanceReload() as headroom
    // frees up - see reapDeadWorkers().
    /** @var list<int> */
    private array $pendingReload = [];

    /**
     * $launcher defaults to actually forking a process — pass a test double
     * to get workers backed by a plain socket pair instead, with no real
     * process involved (see WorkerLauncher).
     */
    public function __construct(
        int $workerCount,
        private readonly WorkerLauncher $launcher = new ForkedWorkerLauncher(),
        private readonly int $maxWorkers = PHP_INT_MAX,
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

    /** STARTING counts as idle here: it means "never dispatched to yet", not "unavailable". */
    public function countIdle(): int
    {
        return count(array_filter($this->workers, static fn (WorkerProcess $w) => $w->isAvailable()));
    }

    public function countBusy(): int
    {
        return count(array_filter($this->workers, static fn (WorkerProcess $w) => $w->getState() === WorkerState::BUSY));
    }

    public function totalCrashed(): int
    {
        return $this->totalCrashed;
    }

    /**
     * Returns the id of any worker that can currently accept a request, or
     * null if all workers are busy, stopping, or dead.
     */
    public function getAvailable(): ?int
    {
        foreach ($this->workers as $id => $worker) {
            if (!isset($this->retiringPids[$id]) && $worker->isAvailable()) {
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
     * A worker in STOPPING when it's reaped was told to stop by us (either
     * stop() or a reload() retirement, PLAN.md Phase 19) - that's an
     * expected exit, not a crash: no WorkerCrash, no totalCrashed bump, and
     * no replacement, since retireIdleWorkers() already launched one when
     * it decided to retire this one.
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

            $expected = $worker->getState() === WorkerState::STOPPING;

            // Capture before markDead() clears it - Dispatcher may
            // independently detect and report the same crash via the
            // worker's closed socket; whichever of the two gets here first
            // is the one that actually has a non-null id to report.
            $lostRequestId = $worker->getCurrentRequestId();
            $worker->markDead();
            unset($this->workers[$pid]);

            if ($expected) {
                continue;
            }

            $crashes[] = new WorkerCrash($worker, $lostRequestId);
            $this->totalCrashed++;

            if ($this->accepting) {
                $replacement = $this->launcher->launch();
                $this->workers[$replacement->getPid()] = $replacement;
            }
        }

        // A reaped worker frees headroom under $maxWorkers - if reload() was
        // waiting on that to replace more of the outgoing generation, this
        // is what lets it keep going.
        if ($this->pendingReload !== []) {
            $this->advanceReload();
        }

        return $crashes;
    }

    /**
     * PLAN.md Phase 19: replaces the whole pool without ever going below
     * its configured size or dropping a request in flight. Starts a full
     * new generation immediately - they're available for new dispatch right
     * away - and marks every worker that existed before this call retiring:
     * excluded from new dispatch, but a busy one is otherwise left
     * completely alone (its current request finishes and gets answered
     * exactly as it would have anyway - Dispatcher never even knows a
     * reload happened). retireIdleWorkers() is what actually shuts a
     * retiring worker down, once it's confirmed idle.
     *
     * A reload already in progress (some previous generation still
     * retiring, or still waiting on $maxWorkers headroom) makes this a
     * no-op - stacking reloads would launch another full generation on top
     * of one that hasn't finished leaving yet, growing the pool instead of
     * replacing it.
     */
    public function reload(): void
    {
        if ($this->retiringPids !== [] || $this->pendingReload !== []) {
            return;
        }

        $this->pendingReload = array_keys($this->workers);
        $this->advanceReload();
    }

    /**
     * Launches a replacement for each pending outgoing worker one at a time,
     * stopping once the pool would exceed $maxWorkers - relevant once
     * Autoscaler may already have grown it close to that ceiling, where
     * launching a full duplicate generation up front (the old behavior)
     * could momentarily double the live process count past it. Always makes
     * progress on at least one, even over the cap, if nothing is currently
     * retiring - otherwise a reload requested while already at $maxWorkers
     * would never start at all.
     */
    private function advanceReload(): void
    {
        while ($this->pendingReload !== [] && (count($this->workers) < $this->maxWorkers || $this->retiringPids === [])) {
            $pid = array_shift($this->pendingReload);

            $worker = $this->launcher->launch();
            $this->workers[$worker->getPid()] = $worker;

            $this->retiringPids[$pid] = true;
        }

        $this->retireIdleWorkers();
    }

    /**
     * Shuts down any retiring worker (see reload()) that has finished
     * whatever it was doing and is now idle. Meant to be polled
     * periodically (Master does this every tick, alongside its other
     * per-tick sweeps) rather than triggered by an event, since nothing
     * currently notifies WorkerPool the moment a specific worker finishes a
     * request.
     */
    public function retireIdleWorkers(): void
    {
        foreach (array_keys($this->retiringPids) as $pid) {
            $worker = $this->workers[$pid] ?? null;

            // isAvailable(), not just "=== IDLE": a retiring worker that was
            // never dispatched to at all (still STARTING) is just as safe
            // to retire right away as one that finished and went IDLE -
            // checking IDLE only would leave a never-used worker retiring
            // forever.
            if ($worker === null || !$worker->isAvailable()) {
                if ($worker === null) {
                    unset($this->retiringPids[$pid]); // gone already (e.g. crashed) - nothing left to retire
                }

                continue;
            }

            $worker->stop();
            $worker->write(new Message(MessageType::SHUTDOWN, 'reload-' . $pid));
            $worker->close();

            unset($this->retiringPids[$pid]);
            // Left in $this->workers on purpose: reapDeadWorkers() (SIGCHLD)
            // will clean it up once the process actually exits, the same
            // way it does for a crash - stop() also still needs to find it
            // here if a shutdown signal arrives before that happens.
        }
    }

    /** PLAN.md Phase 20: starts $count additional workers, immediately available for dispatch. */
    public function scaleUp(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $worker = $this->launcher->launch();
            $this->workers[$worker->getPid()] = $worker;
        }
    }

    /**
     * PLAN.md Phase 20: retires up to $count currently-idle workers - never
     * a busy one, this is routine downscaling under low load, not a reload,
     * so there's no reason to wait on anything. Reuses reload()'s retiring
     * mechanism for a subset rather than the whole pool: mark, then let
     * retireIdleWorkers() (already idle, so this resolves immediately)
     * actually shut them down.
     *
     * @return int how many were actually marked (may be fewer than $count
     *         if there aren't that many idle workers to retire)
     */
    public function scaleDown(int $count): int
    {
        $marked = 0;

        foreach ($this->workers as $pid => $worker) {
            if ($marked >= $count) {
                break;
            }

            if (isset($this->retiringPids[$pid]) || !$worker->isAvailable()) {
                continue;
            }

            $this->retiringPids[$pid] = true;
            $marked++;
        }

        $this->retireIdleWorkers();

        return $marked;
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
        // message — there's nothing left to send it to. One already
        // STOPPING (retireIdleWorkers() got to it first - PLAN.md Phase 19)
        // already had its socket written to and closed; touching it again
        // would write/close an already-closed resource. It still needs to
        // be waited for below either way, just without repeating that.
        $awaiting = 0;

        foreach ($this->workers as $worker) {
            if ($worker->getState() === WorkerState::DEAD) {
                continue;
            }

            if ($worker->getState() !== WorkerState::STOPPING) {
                $worker->stop();
                $worker->write(new Message(MessageType::SHUTDOWN, 'shutdown-' . $worker->getPid()));
                $worker->close();
            }

            $awaiting++;
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
