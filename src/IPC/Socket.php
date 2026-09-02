<?php

declare(strict_types=1);

namespace App\IPC;

use App\Protocol\LengthPrefixedProtocol;
use App\Protocol\MalformedMessageException;
use App\Protocol\Message;

final class Socket
{
    /** @var resource */
    private mixed $socket;
    private LengthPrefixedProtocol $protocol;

    /** @param resource $socket */
    public function __construct(mixed $socket)
    {
        $this->socket = $socket;
        $this->protocol = new LengthPrefixedProtocol();
    }

    /**
     * Reads until at least one complete message is available and returns
     * every message decoded. Partial data stays buffered in the decoder.
     *
     * @return list<Message>
     *
     * @throws MalformedMessageException
     */
    public function read(): array
    {
        while (true) {
            $messages = $this->protocol->decode($this->readChunk());

            if ($messages !== []) {
                return $messages;
            }
        }
    }

    /**
     * Reads whatever is currently available without blocking (waits up to the
     * given timeout for the socket to become readable). Returns all complete
     * messages decoded so far, or an empty array if nothing arrived yet.
     *
     * @return list<Message>
     *
     * @throws MalformedMessageException
     */
    public function readAvailable(int $timeoutSeconds = 0): array
    {
        $read = [$this->socket];
        $write = null;
        $except = null;

        $seconds = $timeoutSeconds;
        $microseconds = 0;

        $ready = @stream_select($read, $write, $except, $seconds, $microseconds);

        if ($ready === false || $ready === 0) {
            return [];
        }

        $messages = $this->protocol->decode($this->readChunk());

        if ($messages !== []) {
            return $messages;
        }

        return $this->readAvailable($timeoutSeconds);
    }

    private function readChunk(): string
    {
        $chunk = fread($this->socket, 8192);

        if ($chunk === false || $chunk === '') {
            throw new MalformedMessageException('Connection closed while awaiting message');
        }

        return $chunk;
    }

    public function write(Message $message): void
    {
        fwrite($this->socket, $this->protocol->encode($message));
    }

    public function close(): void
    {
        fclose($this->socket);
    }
}
