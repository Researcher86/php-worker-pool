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
use LogicException;

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
 * Multiplexing is the Master's model already (PHASES.md Phase 18): responses
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
        // collect() returns null only when a group budget runs out, and
        // await() has no budget: it either produces a payload or throws.
        return $this->collect($pending, null)
            ?? throw new LogicException('await() cannot run out of a budget it was not given');
    }

    /**
     * The shared body of await() and allWithin(): waits for $pending's
     * answer, giving up early - with null - once $budgetDeadline has passed.
     * Null is impossible when $budgetDeadline is null.
     *
     * @return array<string, mixed>|null
     *
     * @throws RequestTimedOutException|ServerErrorException|ConnectionClosedException|MalformedMessageException
     */
    private function collect(PendingResponse $pending, ?float $budgetDeadline): ?array
    {
        $id = $pending->id;

        // Buffered answers are handed over even with the budget already
        // spent: the work is done and the payload is in memory, so throwing
        // it away would be a loss for nothing.
        while (!isset($this->arrived[$id])) {
            if (!isset($this->deadlines[$id])) {
                // Not on the wire and not buffered: either already
                // collected, or from a different client instance.
                throw new LogicException(sprintf('Nothing pending for request "%s" - already awaited?', $id));
            }

            if ($budgetDeadline !== null && microtime(true) >= $budgetDeadline) {
                return null;
            }

            $this->readMore($id, $budgetDeadline);
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
     * Collects whatever answers arrive within ONE budget shared by the whole
     * group, and returns those - a fan-in that degrades instead of failing.
     *
     * The difference from all() is what a missing answer means. all() is
     * "these belong together": one failure fails the call, because a partial
     * result was not what the caller asked for. This is the other case - a
     * page assembled from several sources, where a slow recommendations
     * service should cost its block, not the page. So a handle that times
     * out, is answered with an ERROR, or simply hasn't come back when the
     * budget runs out is left out of the result, and the rest still come
     * back.
     *
     * Why a group budget rather than the per-request timeout: those run from
     * each send() independently, so three requests with a 5s timeout can
     * keep a caller waiting 5s even though two answered in milliseconds -
     * the slowest one sets the pace. Here the caller states the total it is
     * willing to spend, which is the number an HTTP handler actually has.
     *
     * Results keep the position of their handle, so a caller can tell WHICH
     * ones made it:
     *
     *     $answers = $client->allWithin(2.0, $orders, $profile, $recommended);
     *     $page['orders'] = $answers[0] ?? null;      // present = answered
     *     $page['profile'] = $answers[1] ?? null;
     *
     * Handles left uncollected are abandoned: their late answers are
     * discarded when they turn up (readMore already drops anything not on
     * the deadline list) rather than being buffered for a caller who has
     * moved on. Awaiting one afterwards throws LogicException, the same as
     * awaiting twice.
     *
     * A dead connection is NOT degraded away - ConnectionClosedException and
     * MalformedMessageException propagate. Nothing still in flight can
     * arrive over a socket that is gone, so reporting a partial result would
     * be reporting a lie about why it is partial.
     *
     * @return array<int, array<string, mixed>> payloads by handle position;
     *         missing positions did not answer in time or answered with an error
     *
     * @throws ConnectionClosedException|MalformedMessageException
     */
    public function allWithin(float $seconds, PendingResponse ...$pending): array
    {
        $budgetDeadline = microtime(true) + $seconds;
        $payloads = [];

        foreach ($pending as $position => $handle) {
            try {
                $payload = $this->collect($handle, $budgetDeadline);
            } catch (RequestTimedOutException | ServerErrorException) {
                // This source is out; the others are unaffected. Which is
                // the whole point of the method - see the docblock.
                continue;
            }

            if ($payload !== null) {
                $payloads[$position] = $payload;
            }
        }

        // Whatever is still on the wire is no longer ours to wait for.
        // Dropping the deadline is what makes its late answer discardable
        // instead of a buffered payload nobody will ever collect.
        foreach ($pending as $handle) {
            unset($this->deadlines[$handle->id], $this->arrived[$handle->id]);
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
     * $notLaterThan caps that wait without changing what it means to run
     * out: only the request's OWN deadline is a timeout. A group budget
     * (see allWithin) merely stops the waiting, and the caller decides what
     * an exhausted budget means - so this returns having read whatever
     * turned up, rather than throwing.
     *
     * @throws RequestTimedOutException|ConnectionClosedException|MalformedMessageException
     */
    private function readMore(string $waitingFor, ?float $notLaterThan = null): void
    {
        $remaining = $this->deadlines[$waitingFor] - microtime(true);

        if ($remaining <= 0) {
            unset($this->deadlines[$waitingFor]);

            throw new RequestTimedOutException(
                sprintf('No response for request "%s" within %.3fs', $waitingFor, $this->timeoutSeconds)
            );
        }

        if ($notLaterThan !== null) {
            $remaining = max(0.0, min($remaining, $notLaterThan - microtime(true)));
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
