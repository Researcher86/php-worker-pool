<?php

declare(strict_types=1);

namespace App\Worker;

use App\IPC\Socket;
use App\Protocol\Message;

final readonly class WorkerRunner
{
    public function __construct(
        private Socket $socket,
    ) {
    }

    public function run(): void
    {
        while (true) {
            $messages = $this->socket->read();

            foreach ($messages as $message) {
                if ($message->type === 'shutdown') {
                    $this->close();
                    return;
                }

                $this->sendResponse($this->handle($message));
            }
        }
    }

    private function handle(Message $request): Message
    {
        if ($request->type === 'ping') {
            return new Message('pong', $request->id);
        }

        return new Message(
            'response',
            $request->id,
            ['message' => 'PONG   <- Worker'],
        );
    }

    private function sendResponse(Message $response): void
    {
        $this->socket->write($response);
    }

    public function close(): void
    {
        $this->socket->close();
    }
}