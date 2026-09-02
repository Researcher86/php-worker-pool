<?php

declare(strict_types=1);

namespace App\Worker;

use App\IPC\Socket;
use App\Protocol\Message;
use App\Protocol\MessageType;

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
                if ($message->type === MessageType::SHUTDOWN) {
                    $this->close();
                    return;
                }

                $this->sendResponse($this->handle($message));
            }
        }
    }

    private function handle(Message $request): Message
    {
        echo $request->id . "\n";

        return new Message(MessageType::RESPONSE, $request->id, $request->payload);
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