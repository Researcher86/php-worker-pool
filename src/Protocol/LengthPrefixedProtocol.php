<?php

declare(strict_types=1);

namespace App\Protocol;

final readonly class LengthPrefixedProtocol
{
    public function __construct(
        private MessageEncoder $encoder = new MessageEncoder(),
        private MessageDecoder $decoder = new MessageDecoder(),
    ) {
    }

    public function encode(Message $message): string
    {
        return $this->encoder->encode($message);
    }

    /**
     * @return list<Message>
     *
     * @throws \JsonException
     * @throws MalformedMessageException
     */
    public function decode(string $data): array
    {
        return $this->decoder->decode($data);
    }
}
