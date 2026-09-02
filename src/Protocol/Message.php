<?php

declare(strict_types=1);

namespace App\Protocol;

final readonly class Message
{
    public function __construct(
        public readonly MessageType $type,
        public string $id,
        /** @var array<string, mixed> */
        public array $payload = [],
    ) {
    }

    /** @return array{type: string, id: string, payload: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'id' => $this->id,
            'payload' => $this->payload,
        ];
    }
}
