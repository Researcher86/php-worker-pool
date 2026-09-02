<?php

declare(strict_types=1);

namespace App\IPC;

final readonly class Socket
{
    /** @param resource $socket */
    public function __construct(
        private mixed $socket,
    ) {
    }

    /** @return string|false */
    public function read(): string|false
    {
        $line = fgets($this->socket);

        return $line === false ? false : $line;
    }

    public function write(string $data): void
    {
        fwrite($this->socket, $data);
    }

    public function close(): void
    {
        fclose($this->socket);
    }
}
