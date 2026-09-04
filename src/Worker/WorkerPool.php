<?php

declare(strict_types=1);

namespace App\Worker;

use App\Protocol\Message;
use App\Protocol\MessageType;
use App\Support\Clock;
use App\Support\Logger;
use App\Support\NullLogger;
use App\Support\SystemClock;

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

    // Lifetime count of workers replaced because they hit a recycling limit
    // - an expected, healthy event, deliberately counted apart from crashes
    // so a rising number here doesn't read as instability.
    private int $totalRecycled = 0;

    // Lifetime count of workers we killed for blowing the execution limit -
    // counted apart from crashes because they mean something different: a
    // crash is the worker failing, a termination is a REQUEST that never
    // finished.
    private int $totalTerminated = 0;

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
        // Where the recovered-but-otherwise-invisible failures below (launch
        // failures this class deliberately survives instead of rethrowing)
        // get recorded, so they're at least diagnosable after the fact.
        private readonly Logger $logger = new NullLogger(),
        // When to replace a worker with a fresh process - off by default.
        private readonly RecyclingPolicy $recycling = new RecyclingPolicy(),
        private readonly WorkerMemory $memory = new ProcMemory(),
        private readonly Clock $clock = new SystemClock(),
    ) {
        try {
            for ($i = 0; $i < $workerCount; $i++) {
                $this->register($this->launcher->launch());
            }
        } catch (\Throwable $e) {
            // A launch() partway through (e.g. ForkedWorkerLauncher on a
            // failed fork) throws out of the constructor entirely, so
            // $this never reaches the caller - whichever workers already
            // launched here would otherwise be orphaned processes with
            // nothing left holding their pid. stop() (safe to call even
            // with zero workers) kills and reaps them before the failure
            // propagates.
            $this->stop(0.0);

            throw $e;
        }
    }

    /**
     * The one place a freshly launched worker enters the pool: stamped with
     * the pool's own clock so recycling ages every worker against the same
     * timeline, whoever built it.
     */
    private function register(WorkerProcess $worker): WorkerProcess
    {
        $worker->markLaunchedAt($this->clock->now());
        $this->workers[$worker->getPid()] = $worker;

        return $worker;
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

    /**
     * The pool's forward-looking size: workers that are staying, i.e. not
     * draining, not STOPPING, not DEAD. Differs from count() only during
     * transitions - right after reload() the pool briefly holds both the new
     * generation and the outgoing one, and count() sees them all. Scaling
     * decisions must use this one: judging "too many workers" by count()
     * during that window would scale away the NEW generation, since the
     * outgoing one is already excluded from scaleDown()'s candidates.
     */
    public function countActive(): int
    {
        $active = 0;

        foreach ($this->workers as $worker) {
            if (!in_array($worker->getState(), [WorkerState::DRAINING, WorkerState::STOPPING, WorkerState::DEAD], true)) {
                $active++;
            }
        }

        return $active;
    }

    public function totalCrashed(): int
    {
        return $this->totalCrashed;
    }

    /** How many workers have been recycled over the pool's whole lifetime. */
    public function totalRecycled(): int
    {
        return $this->totalRecycled;
    }

    /** How many workers have been killed for exceeding the execution limit. */
    public function totalTerminated(): int
    {
        return $this->totalTerminated;
    }

    /**
     * Returns the id of any worker that can currently accept a request, or
     * null if every worker is busy, draining, stopping, or dead.
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

    /**
     * Dispatches $message to $workerId, or returns null if that worker can
     * no longer take it: with async SIGCHLD, a worker can be reaped (or
     * drained) between the caller's getAvailable() and this call -
     * the deferred section makes the check-and-dispatch atomic against the
     * reaper, and null tells the caller to simply pick another worker.
     */
    public function write(int $workerId, Message $message): ?WorkerProcess
    {
        return $this->withSigchldDeferred(function () use ($workerId, $message): ?WorkerProcess {
            $worker = $this->workers[$workerId] ?? null;

            if ($worker === null || !$worker->isAvailable()) {
                return null;
            }

            $worker->write($message);
            $worker->beginRequest($message->id, $this->clock->now());

            return $worker;
        });
    }

    /**
     * Reaps any worker process that has exited since the last call — never
     * blocks (WNOHANG), so this is safe to call from a SIGCHLD handler.
     * Each crashed worker is removed from the pool and, unless the pool is
     * shutting down, immediately replaced so the pool stays at its
     * configured size.
     *
     * A worker in STOPPING when it's reaped was told to stop by us - a
     * shutdown, or the end of a drain (reload, scale-down, recycling).
     * That's an expected exit, not a crash: no WorkerCrash, no
     * totalCrashed bump, and no replacement, since whoever drained it
     * already launched one.
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

            $worker->isTerminating()
                ? $this->totalTerminated++
                : $this->totalCrashed++;

            if ($this->accepting) {
                try {
                    $this->register($this->launcher->launch());
                } catch (\Throwable $e) {
                    // Same reasoning as advanceReload(): don't let a failed
                    // launch() propagate out of a SIGCHLD handler. Unlike
                    // there, there's no pid to requeue for a specific retry -
                    // this one replacement is simply skipped, leaving the
                    // pool one short of its configured size until something
                    // else grows it (e.g. Autoscaler, under actual load).
                    // What matters here is that this loop keeps going: a
                    // single waitpid() batch can contain more than one dead
                    // worker, and the rest still need to be reaped and
                    // reported below regardless of this one's outcome.
                    $this->logger->log('failed to launch a replacement for crashed worker ' . $pid . ': ' . $e->getMessage());
                }
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
     * away - and drains every worker that existed before this call:
     * excluded from new dispatch, but a busy one is otherwise left
     * completely alone (its current request finishes and gets answered
     * exactly as it would have anyway - Dispatcher never even knows a
     * reload happened). retireIdleWorkers() is what actually shuts a
     * draining worker down, once it has stopped working.
     *
     * A reload already in progress (some previous generation still
     * draining, or still waiting on $maxWorkers headroom) makes this a
     * no-op - stacking reloads would launch another full generation on top
     * of one that hasn't finished leaving yet, growing the pool instead of
     * replacing it.
     */
    public function reload(): void
    {
        $this->withSigchldDeferred(function (): void {
            if ($this->pendingReload !== [] || $this->countDraining() > 0) {
                return;
            }

            $this->pendingReload = array_keys($this->workers);
            $this->advanceReload();
        });
    }

    /**
     * Launches a replacement for each pending outgoing worker one at a time,
     * stopping once the pool would exceed $maxWorkers - relevant once
     * Autoscaler may already have grown it close to that ceiling, where
     * launching a full duplicate generation up front (the old behavior)
     * could momentarily double the live process count past it. Always makes
     * progress on at least one, even over the cap, if nothing is currently
     * draining - otherwise a reload requested while already at $maxWorkers
     * would never start at all.
     */
    private function advanceReload(): void
    {
        while ($this->pendingReload !== [] && (count($this->workers) < $this->maxWorkers || $this->countDraining() === 0)) {
            $pid = array_shift($this->pendingReload);

            try {
                $worker = $this->launcher->launch();
            } catch (\Throwable $e) {
                // Couldn't launch a replacement right now (e.g. a transient
                // fork failure under resource pressure) - put the pid back
                // rather than losing track of it, so a later call (the next
                // reapDeadWorkers(), once something frees up) gets another
                // chance instead of this worker silently never retiring.
                // Not rethrown: this runs from reapDeadWorkers(), which a
                // SIGCHLD handler calls - an uncaught exception there would
                // propagate out of signal delivery, not just out of here.
                $this->logger->log('reload: failed to launch a replacement for worker ' . $pid . ', will retry: ' . $e->getMessage());
                array_unshift($this->pendingReload, $pid);

                break;
            }

            $this->register($worker);

            // The outgoing worker may already be gone (crashed and reaped
            // since the reload started) - its replacement is launched
            // either way, which is the point of the pass.
            ($this->workers[$pid] ?? null)?->drain();
        }

        $this->doRetireIdleWorkers();
    }

    /**
     * Drains every worker that has hit a recycling limit, launching a
     * replacement for each - the routine, healthy version of what
     * reapDeadWorkers() does for a crash.
     *
     * Nothing is interrupted: a worker over its limit while BUSY keeps its
     * request, answers it, and only then exits (retireIdleWorkers(), the
     * next tick). That is why a replacement is launched HERE rather than
     * when the worker finally exits - the pool would otherwise run a worker
     * short for as long as that last request takes.
     *
     * Polled once per Master tick, like the other sweeps. With no policy
     * configured it returns immediately without touching a thing.
     *
     * @return int how many workers were drained this pass
     */
    public function recycleExhaustedWorkers(): int
    {
        if (!$this->recycling->isEnabled()) {
            return 0;
        }

        return $this->withSigchldDeferred(function (): int {
            $now = $this->clock->now();
            $recycled = 0;

            foreach ($this->workers as $pid => $worker) {
                // Only workers still in rotation: one already draining is on
                // its way out anyway, and a STOPPING/DEAD one is gone.
                if ($worker->isDraining() || !in_array($worker->getState(), [WorkerState::STARTING, WorkerState::IDLE, WorkerState::BUSY], true)) {
                    continue;
                }

                $reason = $this->recycling->exhaustedReason(
                    $worker,
                    $now,
                    $this->recycling->maxMemoryBytes === null ? null : $this->memory->measure($pid),
                );

                if ($reason === null) {
                    continue;
                }

                // Replacement first: if it can't be launched right now,
                // leave the tired worker serving rather than shrink the
                // pool. It'll be retried next tick, still over its limit.
                try {
                    $replacement = $this->launcher->launch();
                } catch (\Throwable $e) {
                    $this->logger->log('recycle: keeping worker ' . $pid . ' - no replacement could be launched: ' . $e->getMessage());

                    break;
                }

                $this->register($replacement);

                $worker->drain();
                $this->totalRecycled++;
                $recycled++;

                $this->logger->log(sprintf('recycling worker %d: %s', $pid, $reason));
            }

            $this->doRetireIdleWorkers();

            return $recycled;
        });
    }

    /**
     * Kills any worker that has held one request longer than
     * $limitSeconds - the answer to a handler that will never return.
     *
     * This is deliberately NOT the same thing as a request timeout. That one
     * is about the CLIENT: past its deadline the Master stops making someone
     * wait and answers request_timeout. This one is about the POOL: the
     * client left long ago, but the worker is still occupying a slot, and a
     * handler stuck in an infinite loop would hold it forever - shrinking
     * capacity by one, permanently, per stuck request. Hence two separate
     * limits, and an execution limit that should sit comfortably above the
     * request timeout: by the time it fires, the request is not late, it is
     * never finishing.
     *
     * A drained worker is exempt: it is already leaving, and its request is
     * finishing normally. There is no draining it here either - the whole
     * point is that it will not finish on its own.
     *
     * SIGTERM first (workers run with default handlers, so it ends them
     * immediately), escalating to SIGKILL on a later sweep for anything that
     * somehow survived. Either way SIGCHLD reaps it, its request is reported
     * to whoever is still waiting, and a replacement is forked - the same
     * path a real crash takes.
     *
     * @return int how many were signalled this pass
     */
    public function terminateStuckWorkers(float $limitSeconds): int
    {
        return $this->withSigchldDeferred(function () use ($limitSeconds): int {
            $now = $this->clock->now();
            $signalled = 0;

            foreach ($this->workers as $pid => $worker) {
                $working = $worker->getWorkingSeconds($now);

                if ($working === null || $working < $limitSeconds || $worker->isDraining()) {
                    continue;
                }

                $escalate = $worker->isTerminating();
                posix_kill($pid, $escalate ? SIGKILL : SIGTERM);

                if (!$escalate) {
                    $worker->markTerminating();
                    $signalled++;

                    $this->logger->log(sprintf(
                        'terminating worker %d: request "%s" has run %.1fs (max %.1fs)',
                        $pid,
                        (string) $worker->getCurrentRequestId(),
                        $working,
                        $limitSeconds,
                    ));
                }
            }

            return $signalled;
        });
    }

    /** How many workers are on their way out but not stopped yet. */
    public function countDraining(): int
    {
        return count(array_filter($this->workers, static fn (WorkerProcess $w) => $w->isDraining()));
    }

    /**
     * Shuts down every draining worker that has stopped working - whether it
     * was drained by a reload, a scale-down, or recycling. Meant to be polled
     * periodically (Master does this every tick, alongside its other
     * per-tick sweeps) rather than triggered by an event, since nothing
     * currently notifies WorkerPool the moment a specific worker finishes a
     * request.
     */
    public function retireIdleWorkers(): void
    {
        $this->withSigchldDeferred($this->doRetireIdleWorkers(...));
    }

    private function doRetireIdleWorkers(): void
    {
        foreach ($this->workers as $pid => $worker) {
            // isWorking(), not a state check: a worker drained while BUSY
            // stays DRAINING with its request id until it answers, and one
            // drained before it was ever dispatched to has no id at all -
            // both are "safe to stop" exactly when no request is in flight.
            if (!$worker->isDraining() || $worker->isWorking()) {
                continue;
            }

            $worker->stop();
            $worker->write(new Message(MessageType::SHUTDOWN, 'retire-' . $pid));
            $worker->close();
            // Left in $this->workers on purpose: reapDeadWorkers() (SIGCHLD)
            // will clean it up once the process actually exits, the same
            // way it does for a crash - stop() also still needs to find it
            // here if a shutdown signal arrives before that happens.
        }
    }

    /**
     * PLAN.md Phase 20: starts up to $count additional workers, immediately
     * available for dispatch. Called from Autoscaler::check(), in Master's
     * main loop rather than a signal handler - but a launch() failure
     * partway through (the same transient fork-under-resource-pressure
     * case WorkerPool's own constructor and advanceReload() guard against)
     * would otherwise propagate straight out of that loop and take the
     * whole Master down over what's normally recoverable. Stops early
     * instead, without rethrowing.
     *
     * @return int how many were actually launched (may be fewer than $count)
     */
    public function scaleUp(int $count): int
    {
        return $this->withSigchldDeferred(function () use ($count): int {
            $launched = 0;

            for ($i = 0; $i < $count; $i++) {
                try {
                    $worker = $this->launcher->launch();
                } catch (\Throwable $e) {
                    $this->logger->log(sprintf('scale-up stopped early at %d of %d workers: %s', $launched, $count, $e->getMessage()));

                    break;
                }

                $this->register($worker);
                $launched++;
            }

            return $launched;
        });
    }

    /**
     * PLAN.md Phase 20: retires up to $count currently-idle workers - never
     * a busy one, this is routine downscaling under low load, not a reload,
     * so there's no reason to wait on anything. Reuses the same DRAINING
     * mechanism for a subset rather than the whole pool: drain, then let
     * retireIdleWorkers() (already idle, so this resolves immediately)
     * actually shut them down.
     *
     * @return int how many were actually marked (may be fewer than $count
     *         if there aren't that many idle workers to retire)
     */
    public function scaleDown(int $count): int
    {
        return $this->withSigchldDeferred(function () use ($count): int {
            $marked = 0;

            foreach ($this->workers as $worker) {
                if ($marked >= $count) {
                    break;
                }

                if (!$worker->isAvailable()) {
                    continue; // busy, already draining, stopping or dead
                }

                $worker->drain();
                $marked++;
            }

            $this->doRetireIdleWorkers();

            return $marked;
        });
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
        $this->withSigchldDeferred(fn () => $this->doStop($timeoutSeconds));
    }

    private function doStop(float $timeoutSeconds): void
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

    /**
     * Runs $operation with SIGCHLD delivery deferred - blocked, not ignored:
     * one arriving meanwhile is delivered the moment the mask is restored.
     *
     * With pcntl_async_signals(true) (how Master runs), the SIGCHLD handler
     * - which calls reapDeadWorkers(), mutating $workers and worker states
     * - can otherwise fire between ANY two statements here: in
     * the middle of stop()'s iteration over $workers, between scaleDown()
     * draining a worker and actually retiring it, and so on. Worse,
     * stop()'s own waitpid() loop would compete with the handler's for the
     * same child exits, making its $awaiting count miss workers the handler
     * reaped first. Deferring delivery for the duration of one mutating
     * operation makes each of them atomic with respect to the reaper.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function withSigchldDeferred(callable $operation): mixed
    {
        pcntl_sigprocmask(SIG_BLOCK, [SIGCHLD], $previous);

        try {
            return $operation();
        } finally {
            pcntl_sigprocmask(SIG_SETMASK, $previous);
        }
    }
}
