<?php

declare(strict_types=1);

namespace App\IPC;

/**
 * A connected pair of local sockets used to talk across a pcntl_fork(): one
 * end for the master process, one for the worker.
 *
 * fork() duplicates the parent's open file descriptors, so right after
 * forking BOTH processes have BOTH ends open. Each process must close the
 * end it doesn't own — that's what closeMaster()/closeWorker() are for — so
 * that end's last reference lives only in the other process. Otherwise the
 * master would still hold a reference to the worker's read end even if the
 * worker exits, and reads on the master's end would never see EOF.
 */
final readonly class SocketPair
{
    private Socket $masterSocket;
    private Socket $workerSocket;

    public function __construct()
    {
        [$master, $worker] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $this->masterSocket = new Socket($master);
        $this->workerSocket = new Socket($worker);
    }

    public function getMasterSocket(): Socket
    {
        return $this->masterSocket;
    }

    public function getWorkerSocket(): Socket
    {
        return $this->workerSocket;
    }

    /** Called by the worker process, right after forking, to drop its copy of the master's end. */
    public function closeMaster(): void
    {
        $this->masterSocket->close();
    }

    /** Called by the master process, right after forking, to drop its copy of the worker's end. */
    public function closeWorker(): void
    {
        $this->workerSocket->close();
    }
}
