<?php

declare(strict_types=1);

namespace App\Sdk;

use App\IPC\ConnectionClosedException;
use App\IPC\Socket;
use App\Protocol\MalformedMessageException;
use App\Protocol\Message;
use App\Protocol\MessageType;
use App\Protocol\Payload;
use App\Protocol\Request;

/**
 * Client for talking to a running Master over its Unix domain socket - meant
 * for PHP-FPM, CLI, cron, queue consumers, or any other PHP process.
 *
 * Two ways to call, over one connection this client opens on first use and
 * reuses afterwards:
 *
 *     // one request, block for its answer
 *     $result = $client->call(new Request('calculate', new CalculateRequest(a: 10, b: 20)));
 *
 *     // several at once - all three occupy workers concurrently
 *     $a = $client->send(new Request('calculate', new CalculateRequest(a: 1, b: 2)));
 *     $b = $client->send(new Request('calculate', new CalculateRequest(a: 3, b: 4)));
 *     [$first, $second] = $client->all($a, $b);
 *
 * Nothing runs in the background: send() only writes the request out, and
 * the blocking happens in await()/all(). What that buys is overlap - with
 * call() the pool works on request N+1 only after this process has read
 * answer N, while three sends have three workers busy at once.
 *
 * Multiplexing is the Master's model already (PLAN.md Phase 18): responses
 * carry the correlation id they were sent under and may come back in any
 * order, so this client reads whatever arrives, buffers answers nobody has
 * asked for yet, and hands each one to whoever is waiting for that id.
 */
final class WorkerPoolClient
{
    private ?Socket $connection = null;

    /**
     * Deadline per request still on the wire, by correlation id. Also the
     * record of which ids are ours - a response for anything else (a late
     * answer to a request already timed out, say) is discarded rather than
     * mistaken for someone's.
     *
     * @var array<string, float>
     */
    private array $deadlines = [];

    /**
     * Answers that arrived before anyone asked for them - the normal case
     * when several requests are in flight and they finish out of order.
     *
     * @var array<string, Message>
     */
    private array $arrived = [];

    public function __construct(
        private readonly string $socketPath,
        private readonly float $timeoutSeconds = 5.0,
    ) {
    }

    /**
     * Sends one Request and blocks for its answer - send() plus await(),
     * for the common case of a single round trip.
     *
     * Returns the response payload rather than a Response: a failed one
     * throws instead, so a returned Response could only ever be a
     * successful one - an envelope with nothing left to decide.
     *
     * @return array<string, mixed> whatever the worker's handler returned
     *
     * @throws ConnectionFailedException  couldn't connect to the socket at all
     * @throws RequestTimedOutException   no response within $timeoutSeconds
     * @throws ServerErrorException       the server answered with an ERROR
     *                                    (overloaded, timed out server-side,
     *                                    worker crashed, invalid payload, ...)
     * @throws ConnectionClosedException  the connection dropped mid-request
     * @throws MalformedMessageException  the response didn't parse
     */
    public function call(Request $request): array
    {
        return $this->await($this->send($request));
    }

    /**
     * Writes one Request out and returns immediately with a handle to
     * collect its answer later - the way to have several requests running
     * in the pool at the same time.
     *
     * @throws ConnectionFailedException|ConnectionClosedException
     */
    public function send(Request $request): PendingResponse
    {
        $id = uniqid('req-', true);

        $this->connection()->write(new Message(MessageType::REQUEST, $id, Payload::of($request)));
        $this->deadlines[$id] = microtime(true) + $this->timeoutSeconds;

        return new PendingResponse($this, $id);
    }

    /**
     * Blocks until $pending's answer arrives, collecting and buffering any
     * other pending answers that turn up first. Each request's timeout runs
     * from when IT was sent, not from when it is awaited.
     *
     * @return array<string, mixed>
     *
     * @throws RequestTimedOutException|ServerErrorException|ConnectionClosedException|MalformedMessageException
     */
    public function await(PendingResponse $pending): array
    {
        $id = $pending->id;

        while (!isset($this->arrived[$id])) {
            if (!isset($this->deadlines[$id])) {
                // Not on the wire and not buffered: either already
                // collected, or from a different client instance.
                throw new \LogicException(sprintf('Nothing pending for request "%s" - already awaited?', $id));
            }

            $this->readMore($id);
        }

        $response = $this->arrived[$id];
        unset($this->arrived[$id]);

        // An ERROR is the server refusing or failing the request, not a
        // result - returning its payload as if it were one would make every
        // caller responsible for remembering to check.
        if ($response->type === MessageType::ERROR) {
            $error = $response->payload['error'] ?? null;

            throw new ServerErrorException(is_string($error) ? $error : 'unknown_error', $response->payload);
        }

        return $response->payload;
    }

    /**
     * Collects several pending answers, returning their payloads in the
     * order the handles were given - regardless of the order they actually
     * came back in.
     *
     * Throws on the first failure, like awaiting them one by one would:
     * whoever asked for all of them asked for all of them to succeed. Await
     * individually instead when partial results are worth having.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RequestTimedOutException|ServerErrorException|ConnectionClosedException|MalformedMessageException
     */
    public function all(PendingResponse ...$pending): array
    {
        $payloads = [];

        foreach ($pending as $one) {
            $payloads[] = $this->await($one);
        }

        return $payloads;
    }

    /**
     * Drops the connection and forgets anything still in flight on it. The
     * next send() opens a fresh one; awaiting a handle from before throws.
     */
    public function close(): void
    {
        $this->connection?->close();
        $this->connection = null;
        $this->deadlines = [];
        $this->arrived = [];
    }

    /**
     * One read pass on behalf of $waitingFor: waits up to that request's
     * remaining time for anything to arrive, then files each message under
     * its own id.
     *
     * @throws RequestTimedOutException|ConnectionClosedException|MalformedMessageException
     */
    private function readMore(string $waitingFor): void
    {
        $remaining = $this->deadlines[$waitingFor] - microtime(true);

        if ($remaining <= 0) {
            unset($this->deadlines[$waitingFor]);

            throw new RequestTimedOutException(
                sprintf('No response for request "%s" within %.3fs', $waitingFor, $this->timeoutSeconds)
            );
        }

        try {
            $messages = $this->connection()->readAvailable($remaining);
        } catch (ConnectionClosedException $e) {
            // Nothing still on the wire can arrive now, and the socket is
            // no longer usable - clear it all rather than leave handles that
            // could only ever block.
            $this->close();

            throw $e;
        }

        foreach ($messages as $message) {
            if (!isset($this->deadlines[$message->id])) {
                continue; // not ours, or a late answer to something already given up on
            }

            unset($this->deadlines[$message->id]);
            $this->arrived[$message->id] = $message;
        }
    }

    /** @throws ConnectionFailedException */
    private function connection(): Socket
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        $socket = @stream_socket_client(
            'unix://' . $this->socketPath,
            $errno,
            $errstr,
            $this->timeoutSeconds
        );

        if ($socket === false) {
            throw new ConnectionFailedException(
                sprintf('Could not connect to %s: %s (%d)', $this->socketPath, $errstr, $errno)
            );
        }

        return $this->connection = new Socket($socket);
    }
}
