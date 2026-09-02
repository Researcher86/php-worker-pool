<?php

declare(strict_types=1);

namespace App\Protocol;

final readonly class MessageEncoder
{
    /**
     * Encodes a Message into a length-prefixed binary frame:
     *
     * [ 4 bytes size (big-endian) ][ N bytes JSON payload ]
     *
     * @throws \JsonException
     */
    public function encode(Message $message): string
    {
        $payload = json_encode($message->toArray(), JSON_THROW_ON_ERROR);

        return pack('N', strlen($payload)) . $payload;
    }
}
