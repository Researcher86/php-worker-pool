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
 * application's, injected as $handler and defined where the server is
 * configured (see bin/server.php). The handler declares the payload shape
 * it wants: `array` gets the raw payload, a DTO class gets the payload
 * hydrated into it, and it may hand back a Response, an array, or a DTO -
 * see HandlerAdapter, which reflects the signature once at startup.
 * Everything protocol-shaped stays here on purpose: the response keeps the
 * request's correlation id, and this class alone decides the wire message
 * type, so an application handler cannot break routing no matter what it
 * returns or throws.
 */
final readonly class WorkerRunner
{
    /** @var \Closure(array<string, mixed>): Response */
    private \Closure $handler;

    /** @param \Closure|null $handler application handler in any HandlerAdapter-supported signature */
    public function __construct(
        private Socket $socket,
        ?\Closure $handler = null,
    ) {
        // Default: echo the payload back - the behavior the protocol-level
        // tests rely on, and a sane placeholder until an application
        // provides something real. Adapted like any other handler rather
        // than special-cased, so there's one path to reason about.
        $this->handler = HandlerAdapter::adapt(
            $handler ?? static fn (array $payload): array => $payload
        );
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
                    $response = ($this->handler)($message->payload);
                } catch (PayloadHydrationException) {
                    // The payload doesn't fit the DTO the handler declared -
                    // the CLIENT's fault, reported distinctly from a handler
                    // bug so the caller knows which side to fix.
                    $response = Response::error('invalid_payload');
                } catch (\Throwable) {
                    // A handler bug must not kill the worker: crashing here
                    // would cost the Master a reap-and-refork and turn one
                    // bad request into a worker_crashed for its client, when
                    // an error reply answers it just as definitively - and
                    // the worker stays warm for the next request.
                    $response = Response::error('handler_failed');
                }

                $this->sendResponse($message->id, $response);
            }
        }
    }

    /**
     * The one place a Response becomes a wire message: it always carries the
     * request's own correlation id (the Master routes by it), and the type
     * is decided here from whether the handler reported success - never by
     * the handler directly.
     */
    private function sendResponse(string $requestId, Response $response): void
    {
        $this->socket->write(new Message(
            $response->successful ? MessageType::RESPONSE : MessageType::ERROR,
            $requestId,
            $response->payload,
        ));
    }

    public function close(): void
    {
        $this->socket->close();
    }
}
