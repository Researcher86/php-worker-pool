<?php

declare(strict_types=1);

namespace App\Server;

use App\EventLoop\EventLoop;

/**
 * Listens on a Unix domain socket and hands accepted client connections to a
 * callback. The listener is registered with a shared EventLoop, so it is
 * multiplexed with worker and client sockets in a single stream_select().
 */
final class UnixSocketServer
{
    /** @var resource */
    private mixed $server;

    /** @var \Closure(resource): void */
    private \Closure $onConnect;

    /**
     * @param string           $path     filesystem path of the socket, e.g. /tmp/php-worker-pool.sock
     * @param callable(resource): void $onConnect invoked with each accepted, non-blocking client socket
     */
    public function __construct(
        private readonly string $path,
        private readonly EventLoop $loop,
        callable $onConnect,
    ) {
        $this->onConnect = \Closure::fromCallable($onConnect);

        $this->removeStaleSocketFile();

        $this->server = stream_socket_server(
            'unix://' . $path,
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );

        if ($this->server === false) {
            throw new \RuntimeException(sprintf('Failed to start Unix socket server: %s (%d)', $errstr, $errno));
        }

        stream_set_blocking($this->server, false);

        $this->loop->addReadable($this->server, $this->accept(...));
    }

    /** @return resource */
    public function getListener(): mixed
    {
        return $this->server;
    }

    private function accept(): void
    {
        $client = stream_socket_accept($this->server, 0);

        if ($client === false) {
            return;
        }

        stream_set_blocking($client, false);

        ($this->onConnect)($client);
    }

    public function close(): void
    {
        $this->loop->removeReadable($this->server);
        fclose($this->server);

        if (file_exists($this->path)) {
            unlink($this->path);
        }
    }

    private function removeStaleSocketFile(): void
    {
        if (!file_exists($this->path)) {
            return;
        }

        // A leftover socket file from a previous run that is no longer being
        // listened on blocks binding a new server. Remove it if it isn't a
        // live server (i.e. connecting fails), otherwise leave it alone.
        $probe = @stream_socket_client('unix://' . $this->path, $errno, $errstr, 0.1);

        if ($probe !== false) {
            fclose($probe);

            return; // a live server is already bound here; keep the file
        }

        unlink($this->path);
    }
}