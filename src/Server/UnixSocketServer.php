<?php

declare(strict_types=1);

namespace App\Server;

use App\EventLoop\EventLoop;
use Closure;
use RuntimeException;

/**
 * Listens on a Unix domain socket and hands accepted client connections to a
 * callback. The listener is registered with a shared EventLoop, so it is
 * multiplexed with worker and client sockets in a single stream_select().
 */
final readonly class UnixSocketServer
{
    /**
     * How many pending connections the kernel will hold for us between
     * accepts. PHP's default is small (34 usable in this project's own
     * container), which a burst of clients overruns instantly - and an
     * overrun backlog means the client's connect() fails outright rather
     * than waiting its turn. 511 is the figure nginx uses for the same
     * reason.
     */
    private const int BACKLOG = 511;

    /**
     * Connections accepted per readable event. Accepting only ONE per event
     * loop iteration caps the whole server's connection rate at one per
     * tick, which matters precisely in the case this project is built for -
     * PHP-FPM, where every request is a new connection. Accepting until the
     * listener is drained fixes that, but unbounded it would let a flood of
     * connections starve every other event source for as long as it lasts,
     * so the drain is capped and the rest wait for the next tick.
     */
    private const int MAX_ACCEPTS_PER_TICK = 64;

    /** @var resource */
    private mixed $server;

    /** @var Closure(resource): void */
    private Closure $onConnect;

    /** @param callable(resource): void $onConnect invoked with each accepted, non-blocking client socket */
    public function __construct(
        // Filesystem path of the socket, e.g. /tmp/php-worker-pool.sock.
        private string $path,
        private EventLoop $loop,
        callable $onConnect,
        // Permission bits for the socket file - who may connect.
        private int $mode = 0600,
        // Group to own the socket, or null to leave it as whatever the
        // process's own group is.
        private ?string $group = null,
    ) {
        $this->onConnect = Closure::fromCallable($onConnect);

        $this->removeStaleSocketFile();

        // umask first, chmod after. A Unix socket is a filesystem object and
        // is created honouring the umask - by default 0755, which means
        // every local account can connect and submit work. Creating it
        // restrictively closes the window where it exists at the wrong mode;
        // the chmod below then sets it exactly, since a umask can only
        // remove bits.
        $previousUmask = umask(0777 & ~$this->mode);

        try {
            $this->server = stream_socket_server(
                'unix://' . $path,
                $errno,
                $errstr,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
                stream_context_create(['socket' => ['backlog' => self::BACKLOG]])
            );
        } finally {
            umask($previousUmask);
        }

        if ($this->server === false) {
            throw new RuntimeException(sprintf('Failed to start Unix socket server: %s (%d)', $errstr, $errno));
        }

        $this->applyPermissions();

        stream_set_blocking($this->server, false);

        $this->loop->addReadable($this->server, $this->accept(...));
    }

    /**
     * Sets who may talk to the pool.
     *
     * The default (0600) is owner-only, because the socket is an unauthenticated
     * command channel: anything that can connect can submit work to every
     * worker, and there is no other gate in front of it. The usual production
     * shape is 0660 with a shared group - the Master under its own account,
     * PHP-FPM under www-data, both in one group - which is exactly what
     * php-fpm's own listen.owner / listen.group / listen.mode exist for.
     *
     * A failure here is fatal on purpose: carrying on would leave the socket
     * readable by more of the system than asked for, and a security setting
     * that silently does not apply is worse than one that was never offered.
     */
    private function applyPermissions(): void
    {
        if ($this->group !== null && !@chgrp($this->path, $this->group)) {
            $this->close();

            throw new RuntimeException(sprintf('Could not set group "%s" on %s', $this->group, $this->path));
        }

        if (!@chmod($this->path, $this->mode)) {
            $this->close();

            throw new RuntimeException(sprintf('Could not set mode %o on %s', $this->mode, $this->path));
        }
    }

    /**
     * Drains the accept queue, up to MAX_ACCEPTS_PER_TICK, rather than
     * taking a single connection per readable event.
     *
     * The `@` suppresses the "Accept failed" warning that a zero-timeout
     * accept raises when the queue is empty - which is the normal way this
     * loop ends, not an error.
     */
    private function accept(): void
    {
        for ($accepted = 0; $accepted < self::MAX_ACCEPTS_PER_TICK; $accepted++) {
            $client = @stream_socket_accept($this->server, 0);

            if ($client === false) {
                return; // queue drained (or the readable event was spurious)
            }

            stream_set_blocking($client, false);

            ($this->onConnect)($client);
        }
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
