<?php

declare(strict_types=1);

namespace App\Worker;

use App\IPC\SocketPair;

/**
 * The real WorkerLauncher: forks an OS child process that runs the worker
 * loop forever.
 *
 * $handler is the application's request handler for WorkerRunner (payload
 * in, payload out; null = WorkerRunner's echo default). fork() copies the
 * parent's memory, so a closure defined at server-configuration level (see
 * bin/server.php) reaches every worker - including replacements and
 * scale-ups forked long after startup - without any serialization.
 */
final class ForkedWorkerLauncher implements WorkerLauncher
{
    /** @param \Closure(array<string, mixed>): array<string, mixed>|null $handler */
    public function __construct(
        private readonly ?\Closure $handler = null,
    ) {
    }

    /**
     * pcntl_fork() runs this same function body in TWO processes at once and
     * tells them apart only by its return value: the parent gets the child's
     * pid, the child gets 0. So everything from `if ($pid === 0)` down to
     * `exit(0)` only ever executes inside the freshly forked child — it never
     * returns from there, it just runs the worker loop forever and exits.
     * Everything below that `if` runs only in the parent, which is why the
     * method can return a WorkerProcess: that return only happens for the
     * parent.
     */
    public function launch(): WorkerProcess
    {
        $socketPair = new SocketPair();

        $pid = pcntl_fork();
        if ($pid === -1) {
            // Throwing lets the caller (Master's try/finally) tear down the
            // pool and sockets instead of die()'ing from inside here, which
            // would skip that cleanup.
            throw new \RuntimeException('fork failed');
        }

        if ($pid === 0) {
            // fork() copies the parent's pcntl signal handlers, and a worker
            // forked after Master registered its own (a crash replacement, a
            // scale-up) inherits closures over Master's state: a SIGHUP
            // delivered to this child would run Master's reload handler
            // INSIDE the worker and start forking grandchildren, SIGUSR1
            // would dump Master's metrics from the wrong process, SIGTERM
            // would flip a shutdown flag nothing in the worker reads. Reset
            // every signal Master is known to catch back to the OS default
            // before entering the worker loop.
            foreach ([SIGINT, SIGTERM, SIGCHLD, SIGHUP, SIGUSR1] as $signal) {
                pcntl_signal($signal, SIG_DFL);
            }

            // The signal MASK is inherited too - a worker forked from inside
            // one of WorkerPool's SIGCHLD-deferred sections (a scale-up, a
            // reload wave) would otherwise start life with SIGCHLD blocked.
            pcntl_sigprocmask(SIG_SETMASK, []);

            $socketPair->closeMaster();

            $runner = new WorkerRunner($socketPair->getWorkerSocket(), $this->handler);
            $runner->run();

            exit(0);
        }

        $socketPair->closeWorker();

        return new WorkerProcess($pid, $socketPair->getMasterSocket());
    }
}
