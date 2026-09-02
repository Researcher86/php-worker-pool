<?php

namespace App\Worker;

use App\IPC\Socket;

final readonly class WorkerRunner
{
    public function __construct(
        private Socket $socket,
    ) {
    }

    public function run(): void
    {
        while (true) {
            $request = $this->receiveRequest();

            if (str_contains($request, 'STOP')) {
                break;
            }

            $response = $this->handle($request);

            $this->sendResponse($response);
        }

        $this->close();
    }

    private function receiveRequest(): string|false
    {
        return $this->socket->read();
    }

    private function handle(string|false $request): string
    {
        echo $request;

        return "PONG   <- Worker\n";
    }

    private function sendResponse(string $response): void
    {
        $this->socket->write($response);
    }

    public function close(): void
    {
        $this->socket->close();
    }
}
