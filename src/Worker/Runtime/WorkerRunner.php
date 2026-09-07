<?php

declare(strict_types=1);

namespace App\Worker\Runtime;

use App\IPC\ConnectionClosedException;
use App\IPC\Socket;
use App\Protocol\Message;
use App\Protocol\MessageType;
use App\Protocol\PayloadHydrationException;
use App\Protocol\PayloadHydrator;
use App\Protocol\Request;
use App\Protocol\Response;
use App\Support\Logger;
use App\Support\NullLogger;
use App\Worker\Telemetry\TelemetrySlot;
use Closure;
use Throwable;

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
    /** @var Closure(Request): Response */
    private Closure $handler;

    /** @param Closure(Request): Response|null $handler */
    public function __construct(
        private Socket $socket,
        ?Closure $handler = null,
        // Where this worker publishes what only it can measure about itself
        // - null when the Master couldn't set up shared memory, or in tests
        // that don't care (see SharedTelemetry).
        private ?TelemetrySlot $slot = null,
        /** @var (Closure(Logger): void)|null */
        // The application's warm-up, run once inside the worker before it
        // announces itself: open the database connection, prime a cache,
        // load whatever a first request should not have to pay for. Null
        // means there is nothing to warm and READY goes out immediately.
        //
        // It receives the Master's own Logger, inherited through fork(), so
        // a warm-up reports through the same channel as everything else the
        // runtime says - one format, one destination, and swappable in a
        // test - instead of each application reaching for fwrite(STDERR).
        private ?Closure $bootstrap = null,
        private Logger $logger = new NullLogger(),
    ) {
        // Default: echo the params back - a sane placeholder until an
        // application provides something real, and what the protocol-level
        // tests (which care about framing, not about what a worker computes)
        // rely on.
        $this->handler = $handler ?? static fn (Request $request): Response => new Response($request->params);
    }

    public function run(): void
    {
        // Bootstrap first, and deliberately unguarded: a worker whose warm-up
        // failed must not go on to announce itself as ready. Letting the
        // throwable kill the process is exactly right - the Master reaps it
        // like any crash and forks a replacement, instead of the pool filling
        // up with workers that answer every request with handler_failed.
        if ($this->bootstrap !== null) {
            ($this->bootstrap)($this->logger);
        }

        // A baseline before any work: until a worker has published once, the
        // Master has no reading for it at all and its memory limit simply
        // isn't enforced. Taken after the bootstrap so the first reading
        // describes a warmed-up worker rather than an empty one.
        $this->publishVitals();

        // The handshake. Until this lands, the Master's WorkerProcess is
        // STARTING and its transition table refuses to dispatch here - so
        // this line is what puts the worker into rotation.
        $this->socket->write(new Message(MessageType::READY, (string) posix_getpid()));

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

                // After the reply, not before: the response is out the door
                // first, and the reading the Master gets is the one that
                // matters for recycling - what this request LEFT allocated.
                $this->publishVitals();
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
        } catch (Throwable) {
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

    /**
     * memory_get_usage(true) is the whole reason this exists: it reports what
     * PHP has actually taken from the OS in THIS process, which no one
     * outside it can ask for, and which is the quantity a memory recycling
     * limit is about (see ShmWorkerMemory).
     *
     * microtime() rather than the Master's Clock abstraction on purpose -
     * the timestamp is read by a different process, so it has to come from
     * the one clock both of them genuinely share, not from something a test
     * could have replaced on one side only.
     */
    private function publishVitals(): void
    {
        $this->slot?->publish(memory_get_usage(true), microtime(true));
    }

    public function close(): void
    {
        $this->socket->close();
    }
}
