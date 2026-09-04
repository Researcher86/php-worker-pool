<?php

declare(strict_types=1);

namespace App\Worker;

use App\IPC\ConnectionClosedException;
use App\IPC\Socket;
use App\Protocol\Message;
use App\Protocol\MessageType;

/**
 * The loop a worker process runs for its whole life: read a request, hand it
 * to the application handler, write the response back.
 *
 * The handler contract is fixed: `Request in, Response out`. Every request
 * payload is hydrated into the Request envelope (action + params) before the
 * call, and the handler decides what the client sees by which Response it
 * returns - a result, or a named error. An action's own DTO is hydrated by
 * the handler from `$request->params` (see PayloadHydrator and
 * bin/server.php), which keeps every action an ordinary typed function
 * without the runtime having to guess anything from a callback's signature.
 *
 * What requests actually DO is not this class's business - it's the
 * application's, injected as $handler and defined where the server is
 * configured. Everything protocol-shaped stays here on purpose: the reply
 * always carries the request's own correlation id and this class alone
 * decides the wire message type, so an application handler cannot break
 * routing no matter what it returns or throws.
 */
final readonly class WorkerRunner
{
    /** @var \Closure(Request): Response */
    private \Closure $handler;

    /** @param \Closure(Request): Response|null $handler */
    public function __construct(
        private Socket $socket,
        ?\Closure $handler = null,
    ) {
        // Default: echo the params back - a sane placeholder until an
        // application provides something real, and what the protocol-level
        // tests (which care about framing, not about what a worker computes)
        // rely on.
        $this->handler = $handler ?? static fn (Request $request): Response => new Response($request->params);
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

                $this->socket->write($this->handle($message));
            }
        }
    }

    private function handle(Message $request): Message
    {
        try {
            // toMessage() inside the try on purpose: a handler that doesn't
            // declare its return type could hand back something that isn't a
            // Response, and PHP's own parameter check turns that into a
            // TypeError the catch below reports as handler_failed - rather
            // than it escaping and killing the worker.
            return $this->toMessage(
                $request->id,
                ($this->handler)(PayloadHydrator::hydrate(Request::class, $request->payload))
            );
        } catch (PayloadHydrationException) {
            // The payload doesn't fit the Request envelope, or the action's
            // own DTO the handler hydrated from it - the CLIENT's fault,
            // reported distinctly from a handler bug so the caller knows
            // which side to fix.
            return $this->toMessage($request->id, Response::error('invalid_payload'));
        } catch (\Throwable) {
            // A handler bug must not kill the worker: crashing here would
            // cost the Master a reap-and-refork and turn one bad request
            // into a worker_crashed for its client, when an error reply
            // answers it just as definitively - and the worker stays warm
            // for the next request.
            return $this->toMessage($request->id, Response::error('handler_failed'));
        }
    }

    /**
     * The one place a Response becomes a wire message: it always carries the
     * request's own correlation id (the Master routes by it), and the type
     * is decided here from whether the handler reported success - never by
     * the handler directly.
     */
    private function toMessage(string $requestId, Response $response): Message
    {
        return new Message(
            $response->successful ? MessageType::RESPONSE : MessageType::ERROR,
            $requestId,
            $response->payload,
        );
    }

    public function close(): void
    {
        $this->socket->close();
    }
}
