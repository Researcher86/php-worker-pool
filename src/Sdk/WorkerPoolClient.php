<?php

declare(strict_types=1);

namespace App\Sdk;

use App\IPC\ConnectionClosedException;
use App\IPC\Socket;
use App\Protocol\MalformedMessageException;
use App\Protocol\Message;
use App\Protocol\MessageType;

/**
 * Minimal synchronous client for talking to a running Master over its Unix
 * domain socket - meant for PHP-FPM, CLI, cron, queue consumers, or any
 * other PHP process that needs a single request/response round trip.
 *
 * Each call() opens a fresh connection, sends one request, blocks for its
 * matching response, and closes the connection - no pooling or state kept
 * between calls, so one instance is safe to reuse for many calls over a
 * long-lived process (a queue consumer) or construct fresh per request
 * (PHP-FPM).
 */
final readonly class WorkerPoolClient
{
    public function __construct(
        private string $socketPath,
        private float $timeoutSeconds = 5.0,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed> the response payload, i.e. whatever the
     *         worker's handler returned for this action
     *
     * @throws ConnectionFailedException  couldn't connect to the socket at all
     * @throws RequestTimedOutException   no response within $timeoutSeconds
     * @throws ConnectionClosedException  the connection dropped mid-request
     * @throws MalformedMessageException  the response didn't parse
     */
    public function call(string $action, array $params = []): array
    {
        $connection = $this->connect();

        try {
            $id = uniqid('req-', true);

            $connection->write(new Message(MessageType::REQUEST, $id, [
                'action' => $action,
                'params' => $params,
            ]));

            return $this->awaitResponse($connection, $id)->payload;
        } finally {
            $connection->close();
        }
    }

    private function connect(): Socket
    {
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

        return new Socket($socket);
    }

    /** @throws RequestTimedOutException|ConnectionClosedException|MalformedMessageException */
    private function awaitResponse(Socket $connection, string $id): Message
    {
        $deadline = microtime(true) + $this->timeoutSeconds;

        while (true) {
            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                throw new RequestTimedOutException(
                    sprintf('No response for request "%s" within %.3fs', $id, $this->timeoutSeconds)
                );
            }

            // A stray message with a different id shouldn't happen on a
            // connection this client opened solely for this one request,
            // but skipping rather than trusting "first message in" keeps
            // this correct even so.
            foreach ($connection->readAvailable($remaining) as $message) {
                if ($message->id === $id) {
                    return $message;
                }
            }
        }
    }
}
