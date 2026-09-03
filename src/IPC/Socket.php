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
        // Writing to a peer that's already gone raises a "Broken pipe"
        // warning; the `@` suppresses it. That case is already handled on
        // the read side — the next read on this socket throws
        // ConnectionClosedException — so there's nothing more to do here.
        @fwrite($this->socket, $this->encoder->encode($message));
    }

    public function close(): void
    {
        fclose($this->socket);
    }
}
