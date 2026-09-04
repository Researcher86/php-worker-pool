<?php

declare(strict_types=1);

namespace App\Worker;

use App\IPC\ConnectionClosedException;
use App\IPC\Socket;
use App\Protocol\Message;
use App\Protocol\MessageType;

/**
 * The loop a worker process runs for its whole life: read a request, hand
 * its payload to the application handler, write the response back.
 *
 * What requests actually DO is not this class's business - it's the
 * application's, injected as $handler (payload in, payload out) and defined
 * where the server is configured (see bin/server.php). Everything protocol-
 * shaped stays here on purpose: the response keeps the request's correlation
 * id and the RESPONSE/ERROR envelope is applied by this class, so an
 * application handler cannot break routing no matter what it returns or
 * throws.
 */
final readonly class WorkerRunner
{
    /** @var \Closure(array<string, mixed>): array<string, mixed> */
    private \Closure $handler;

    /** @param \Closure(array<string, mixed>): array<string, mixed>|null $handler */
    public function __construct(
        private Socket $socket,
        ?\Closure $handler = null,
    ) {
        // Default: echo the payload back - the behavior the protocol-level
        // tests rely on, and a sane placeholder until an application
        // provides something real.
        $this->handler = $handler ?? static fn (array $payload): array => $payload;
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
        return new Message(MessageType::RESPONSE, $request->id, ($this->handler)($request->payload));
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
