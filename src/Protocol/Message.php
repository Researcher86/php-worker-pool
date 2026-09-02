<?php

declare(strict_types=1);

namespace App\Protocol;

final readonly class Message
{
    public function __construct(
        public MessageType $type,
        public string $id,
        /** @var array<string, mixed> */
        public array $payload = [],
    ) {
    }
}
