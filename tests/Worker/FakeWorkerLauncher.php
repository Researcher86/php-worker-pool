<?php

declare(strict_types=1);

namespace App\Tests\Worker;

use App\IPC\Socket;
use App\IPC\SocketPair;
use App\Worker\WorkerLauncher;
use App\Worker\WorkerProcess;
use App\Worker\WorkerState;

/**
 * Test double for WorkerLauncher: wires each "worker" to a real socket pair
 * but never forks a process. The test keeps the other end of that pair
 * (workerEnds()) and drives it directly — write a Message to simulate the
 * worker answering, close it to simulate the worker dying — instead of
 * needing a real forked process to react to input.
 *
 * Workers come out IDLE rather than STARTING: there is no process here to
 * ever send READY, and a pool full of permanently-STARTING workers would
 * make every test about something else fail for a reason that has nothing to
 * do with what it tests. Pass $starting to get the real initial state -
 * ReadinessTest does, because the handshake IS what it is testing.
 */
final class FakeWorkerLauncher implements WorkerLauncher
{
    private int $nextPid = 90000;

    /** @var list<Socket> */
    private array $workerEnds = [];

    public function __construct(
        // Whether launched workers still owe a READY, as real ones do.
        private readonly bool $starting = false,
    ) {
    }

    public function launch(): WorkerProcess
    {
        $pair = new SocketPair();

        $this->workerEnds[] = $pair->getWorkerSocket();

        return new WorkerProcess(
            $this->nextPid++,
            $pair->getMasterSocket(),
            $this->starting ? WorkerState::STARTING : WorkerState::IDLE,
        );
    }

    /** @return list<Socket> the worker-side end of every pair created so far, in launch() order */
    public function workerEnds(): array
    {
        return $this->workerEnds;
    }
}
