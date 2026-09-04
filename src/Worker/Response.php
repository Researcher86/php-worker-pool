<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * The counterpart of Request: what an application handler answers with.
 *
 *     return match ($request->action) {
 *         'calculate' => Response::of(calculate(...)),   // a result DTO
 *         'ping'      => new Response(['pong' => true]), // a plain payload
 *         default     => Response::error('unknown_action'),
 *     };
 *
 * A handler may still return a bare array or DTO - the runtime wraps it (see
 * HandlerAdapter) - but returning a Response is what makes the FAILURE case
 * expressible: until now the only way for a handler to fail a request was to
 * throw, which reports handler_failed and says nothing about what was
 * actually wrong. Response::error() answers the client with an ERROR message
 * carrying a code the application chose, which WorkerPoolClient turns back
 * into a ServerErrorException whose ->error is that same code.
 *
 * $successful is deliberately a bool rather than a Protocol\MessageType:
 * application code shouldn't have to know the wire protocol's vocabulary -
 * WorkerRunner does that mapping.
 */
final readonly class Response
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public array $payload = [],
        public bool $successful = true,
    ) {
    }

    /**
     * A successful answer built from a result DTO (or an array, passed
     * through). An object contributes its JSON-visible state - public
     * properties, or whatever JsonSerializable returns - which is the same
     * view the wire encoding would take of it anyway.
     *
     * @param array<string, mixed>|object $data
     */
    public static function of(array|object $data): self
    {
        return new self(self::toPayload($data));
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

    /**
     * @param array<string, mixed>|object $data
     *
     * @return array<string, mixed>
     */
    private static function toPayload(array|object $data): array
    {
        if (is_array($data)) {
            return $data;
        }

        $decoded = json_decode(json_encode($data, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf('%s does not expose any JSON-visible state to respond with', $data::class));
        }

        /** @var array<string, mixed> */
        return $decoded;
    }
}
