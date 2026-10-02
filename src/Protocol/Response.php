<?php

declare(strict_types=1);

namespace PhpWorkerPool\Protocol;

/**
 * The counterpart of Request: what an application handler answers with.
 *
 *     return match ($request->action) {
 *         'calculate' => Response::of(calculate(...)),   // a result DTO
 *         'ping'      => new Response(['pong' => true]), // a plain payload
 *         default     => Response::error('unknown_action'),
 *     };
 *
 * A handler must return a Response (anything else is reported as
 * handler_failed, see WorkerRunner), and that is what makes the FAILURE case
 * expressible: throwing reports handler_failed and says nothing about what
 * was actually wrong, while Response::error() answers the client with an
 * ERROR message carrying a code the application chose, which
 * WorkerPoolClient turns back into a ServerErrorException whose ->error is
 * that same code.
 *
 * $successful is deliberately a bool rather than a Protocol\MessageType:
 * application code shouldn't have to know the wire protocol's vocabulary -
 * WorkerRunner does that mapping.
 */
final readonly class Response
{
    public function __construct(
        /** @var array<string, mixed> */
        public array $payload = [],
        public bool $successful = true,
    ) {
    }

    /**
     * A successful answer built from a result DTO (or an array, passed
     * through) - see Protocol\Payload for how an object becomes one, the
     * same rule the SDK applies to a request DTO on the way in.
     *
     * @param array<string, mixed>|object $data
     */
    public static function of(array|object $data): self
    {
        return new self(Payload::of($data));
    }

    /**
     * A deliberate application-level failure: the client receives an ERROR
     * message instead of a response, and the SDK throws ServerErrorException
     * with $error as its code.
     *
     * The payload follows the same `{"error": "..."}` convention as every
     * error the runtime itself produces (server_overloaded, request_timeout,
     * worker_crashed, ...). $details may carry extra context alongside it
     * but cannot displace the code itself.
     *
     * @param array<string, mixed> $details
     */
    public static function error(string $error, array $details = []): self
    {
        return new self(['error' => $error] + $details, successful: false);
    }
}
