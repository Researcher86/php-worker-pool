<?php

declare(strict_types=1);

namespace App\Protocol;

use JsonException;
use RuntimeException;

/**
 * The one rule for turning application data into a Message payload: an array
 * goes as-is, an object contributes its JSON-visible state - public
 * properties, or whatever JsonSerializable returns - which is exactly the
 * view the wire encoding would take of it anyway.
 *
 * Lives in Protocol because both ends must agree on it: Request when a
 * caller passes a request DTO, Response when a handler answers with a
 * result DTO.
 */
final readonly class Payload
{
    /**
     * @param array<string, mixed>|object $data
     *
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public static function of(array|object $data): array
    {
        if (is_array($data)) {
            return $data;
        }

        $decoded = json_decode(json_encode($data, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('%s does not expose any JSON-visible state to send', $data::class));
        }

        /** @var array<string, mixed> */
        return $decoded;
    }
}
