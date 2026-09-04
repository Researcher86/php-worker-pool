<?php

declare(strict_types=1);

namespace App\Worker;

use App\IPC\ConnectionClosedException;
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
            try {
                $messages = $this->socket->read();
            } catch (ConnectionClosedException) {
                // The master's end of the socket went away (process killed,
                // crashed, etc.) without ever sending SHUTDOWN. Exit the same
                // way we would have on an explicit shutdown, just without a
                // message to reply "goodbye" to.
                $this->close();
                return;
            }

            foreach ($messages as $message) {
                if ($message->type === MessageType::SHUTDOWN) {
                    $this->close();
                    return;
                }

                try {
                    $response = $this->handle($message);
                } catch (\Throwable) {
                    // A handler bug must not kill the worker: crashing here
                    // would cost the Master a reap-and-refork and turn one
                    // bad request into a worker_crashed for its client, when
                    // an error reply answers it just as definitively - and
                    // the worker stays warm for the next request.
                    $response = new Message(MessageType::ERROR, $message->id, ['error' => 'handler_failed']);
                }

                $this->sendResponse($response);
            }
        }
    }

    private function handle(Message $request): Message
    {
        return match ($request->payload['action'] ?? null) {
            'calculate' => new Message(MessageType::RESPONSE, $request->id, [
                'result' => $request->payload['params']['a'] + $request->payload['params']['b'],
            ]),
            // No action (or an unrecognized one) - keep the old echo-back
            // behavior the rest of the test suite relies on.
            default => new Message(MessageType::RESPONSE, $request->id, $request->payload),
        };
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