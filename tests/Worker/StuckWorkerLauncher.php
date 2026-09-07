<?php

declare(strict_types=1);

namespace App\Tests\Worker;

use App\IPC\SocketPair;
use App\Worker\WorkerLauncher;
use App\Worker\WorkerProcess;
use App\Worker\WorkerState;

/**
 * Test double for WorkerLauncher: forks a real process, but one that never
 * reads its socket at all — it never sees a SHUTDOWN message, simulating a
 * worker that's stuck (or just far slower than the shutdown timeout). Used
 * to prove WorkerPool::stop()'s SIGKILL fallback actually fires rather than
 * hanging forever.
 */
final class StuckWorkerLauncher implements WorkerLauncher
{
    public function launch(): WorkerProcess
    {
        $socketPair = new SocketPair();

        $pid = pcntl_fork();
        if ($pid === -1) {
            die('fork failed');
        }

        if ($pid === 0) {
            $socketPair->closeMaster();
            sleep(60); // long enough to never exit on its own during a test
            exit(0);
        }

        $socketPair->closeWorker();

        // IDLE, not STARTING: what this double models is a worker that got
        // through its bootstrap and then hung on a REQUEST - the execution
        // timeout's case. A worker that never became ready is a different
        // failure with a different sweep (the bootstrap timeout), and the
        // child above deliberately says nothing at all, so it could never
        // complete a handshake anyway.
        return new WorkerProcess($pid, $socketPair->getMasterSocket(), WorkerState::IDLE);
    }
}
