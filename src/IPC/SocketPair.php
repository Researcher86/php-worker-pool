<?php

declare(strict_types=1);

namespace App\IPC;

final class SocketPair
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

    public function closeMaster(): void
    {
        $this->masterSocket->close();
    }

    public function closeWorker(): void
    {
        $this->workerSocket->close();
    }
}
