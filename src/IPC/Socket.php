<?php

declare(strict_types=1);

namespace App\IPC;

use App\Protocol\MalformedMessageException;
use App\Protocol\Message;
use App\Protocol\MessageDecoder;
use App\Protocol\MessageEncoder;

final readonly class Socket
{
    public function __construct(
        /** @var resource */
        private mixed $socket,
        private MessageEncoder $encoder = new MessageEncoder(),
        private MessageDecoder $decoder = new MessageDecoder(),
        // Client sockets are non-blocking (UnixSocketServer::accept()) - a
        // full kernel send buffer makes fwrite() return fewer bytes than
        // asked instead of blocking for room, so write() below has to keep
        // retrying. Bounds how long a single slow/stuck peer can stall the
        // rest of write()'s caller (the single-threaded Master) rather than
        // waiting on it forever.
        private float $writeTimeoutSeconds = 5.0,
    ) {
    }

    /** @return resource */
    public function getResource(): mixed
    {
        return $this->socket;
    }

    /**
     * Reads until at least one complete message is available and returns
     * every message decoded. Partial data stays buffered in the decoder.
     *
     * @return list<Message>
     *
     * @throws MalformedMessageException
     * @throws ConnectionClosedException
     */
    public function read(): array
    {
        while (true) {
            $messages = $this->decoder->decode($this->readChunk());

            if ($messages !== []) {
                return $messages;
            }
        }
    }

    /**
     * Polls the socket once and returns whatever complete messages are
     * currently available, waiting up to $timeoutSeconds for the socket to
     * become readable (0, the default, means "don't wait at all"). Returns
     * an empty array if nothing arrived within the timeout.
     *
     * @return list<Message>
     *
     * @throws MalformedMessageException
     * @throws ConnectionClosedException
     */
    public function readAvailable(float $timeoutSeconds = 0.0): array
    {
        $read = [$this->socket];
        $write = [];
        $except = [];

        // stream_select()'s timeout is a whole-seconds part plus a
        // microseconds remainder (the microseconds argument alone can't
        // safely represent multi-second waits), so split $timeoutSeconds
        // into both.
        $seconds = (int) $timeoutSeconds;
        $microseconds = (int) (($timeoutSeconds - $seconds) * 1_000_000);

        // stream_select() returns the count of streams that became ready;
        // with exactly one candidate socket, anything other than 1 means it
        // didn't become readable within the timeout. The `@` swallows the
        // "Interrupted system call" warning stream_select can raise if an
        // unrelated OS signal arrives mid-call — harmless here, we just
        // treat it the same as "nothing ready yet".
        if (@stream_select($read, $write, $except, $seconds, $microseconds) !== 1) {
            return [];
        }

        return $this->decoder->decode($this->readChunk());
    }

    private function readChunk(): string
    {
        // fread() returns '' on a clean peer close (EOF) and false on a broken
        // pipe / reset connection — depending on OS timing, a killed peer can
        // surface as either. Both mean the same thing at this layer: the
        // connection is gone, not that a message was malformed.
        $chunk = fread($this->socket, 8192);

        if ($chunk === false || $chunk === '') {
            throw new ConnectionClosedException('Connection closed while awaiting message');
        }

        return $chunk;
    }

    public function write(Message $message): void
    {
        $data = $this->encoder->encode($message);
        $length = strlen($data);
        $offset = 0;
        $deadline = microtime(true) + $this->writeTimeoutSeconds;

        while ($offset < $length) {
            // Writing to a peer that's already gone raises a "Broken pipe"
            // warning; the `@` suppresses it. fwrite() returning false means
            // that - already handled on the read side, where the next
            // read() on this socket throws ConnectionClosedException - so
            // there's nothing more to do here than stop.
            $written = @fwrite($this->socket, substr($data, $offset));

            if ($written === false) {
                return;
            }

            $offset += $written;

            if ($offset >= $length) {
                break;
            }

            // A non-blocking socket's fwrite() can write fewer bytes than
            // asked (0 included) once its kernel send buffer is full,
            // instead of blocking until there's room for the rest - an
            // unretried fwrite() here would silently drop the remainder of
            // the frame on a slow peer instead of a dead one. Wait for the
            // socket to become writable again rather than busy-spinning
            // fwrite() in the meantime.
            if (microtime(true) >= $deadline) {
                return; // peer isn't draining its buffer fast enough - give up, same as a dead one
            }

            $write = [$this->socket];
            $read = [];
            $except = [];
            @stream_select($read, $write, $except, 1);
        }
    }

    public function close(): void
    {
        fclose($this->socket);
    }
}
