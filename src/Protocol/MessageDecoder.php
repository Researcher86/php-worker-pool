<?php

declare(strict_types=1);

namespace App\Protocol;

final class MessageDecoder
{
    private string $buffer = '';

    public function __construct(
        private readonly int $maxMessageSize = 1048576,
    ) {
    }

    /**
     * Feeds raw bytes and returns all complete messages found so far.
     * Partial leftovers stay in the internal buffer until more data arrives.
     *
     * @return list<Message>
     *
     * @throws \JsonException
     * @throws MalformedMessageException
     */
    public function decode(string $data): array
    {
        $this->buffer .= $data;

        $messages = [];
        $offset = 0;
        $length = strlen($this->buffer);

        while ($length - $offset >= 4) {
            $size = unpack('N', substr($this->buffer, $offset, 4))[1];

            if ($size > $this->maxMessageSize) {
                throw new MalformedMessageException(
                    sprintf('Message size %d exceeds limit %d', $size, $this->maxMessageSize)
                );
            }

            if ($length - $offset < 4 + $size) {
                break; // wait for the rest of the message
            }

            $payload = substr($this->buffer, $offset + 4, $size);
            $offset += 4 + $size;

            $messages[] = $this->parse($payload);
        }

        if ($offset > 0) {
            $this->buffer = substr($this->buffer, $offset);
        }

        return $messages;
    }

    /**
     * @throws \JsonException
     * @throws MalformedMessageException
     */
    private function parse(string $payload): Message
    {
        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MalformedMessageException('Invalid JSON payload: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data)
            || !isset($data['type'], $data['id'])
            || !is_string($data['type'])
            || !is_string($data['id'])
        ) {
            throw new MalformedMessageException('Message missing type/id or wrong field type');
        }

        $messageType = MessageType::tryFrom($data['type']);

        if ($messageType === null) {
            throw new MalformedMessageException(sprintf('Unknown message type: %s', $data['type']));
        }

        $payloadArr = isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : [];

        return new Message($messageType, $data['id'], $payloadArr);
    }
}
