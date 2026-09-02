<?php

declare(strict_types=1);

namespace App\Protocol;

final readonly class Message
{
    public function __construct(
        public string $type,
        public string $id,
        /** @var array<string, mixed> */
        public array $payload = [],
    ) {
    }

    /** @return array{type: string, id: string, payload: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
            'payload' => $this->payload,
        ];
    }
}
