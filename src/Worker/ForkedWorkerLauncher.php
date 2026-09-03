<?php

declare(strict_types=1);

namespace App\Worker;

use App\IPC\SocketPair;

/**
 * The real WorkerLauncher: forks an OS child process that runs the worker
 * loop forever.
 */
final class ForkedWorkerLauncher implements WorkerLauncher
{
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
            die('fork failed');
        }

        if ($pid === 0) {
            $socketPair->closeMaster();

            $runner = new WorkerRunner($socketPair->getWorkerSocket());
            $runner->run();

            exit(0);
        }

        $socketPair->closeWorker();

        return new WorkerProcess($pid, $socketPair->getMasterSocket());
    }
}
