<?php

declare(strict_types=1);

namespace App\Tests\Protocol;

use App\Protocol\MalformedMessageException;
use App\Protocol\Message;
use App\Protocol\MessageDecoder;
use App\Protocol\MessageEncoder;
use App\Protocol\MessageType;
use PHPUnit\Framework\TestCase;

/**
 * Round-trips MessageEncoder + MessageDecoder together — the framing and
 * JSON logic is plain and socket-free, so this exercises it with strings
 * directly, no real socket or forked process needed.
 */
final class MessageCodecTest extends TestCase
{
    private MessageEncoder $encoder;
    private MessageDecoder $decoder;

    protected function setUp(): void
    {
        $this->encoder = new MessageEncoder();
        $this->decoder = new MessageDecoder();
    }

    public function testSingleMessageRoundTrip(): void
    {
        $message = new Message(MessageType::REQUEST, 'req-1', ['action' => 'calculate']);

        $frame = $this->encoder->encode($message);
        $decoded = $this->decoder->decode($frame);

        $this->assertCount(1, $decoded);
        $this->assertEquals($message, $decoded[0]);
    }

    public function testPartialMessageAccumulatesUntilComplete(): void
    {
        $message = new Message(MessageType::REQUEST, 'req-partial', ['a' => 'b']);
        $frame = $this->encoder->encode($message);

        $half = (int) floor(strlen($frame) / 2);

        $this->assertSame([], $this->decoder->decode(substr($frame, 0, $half)));
        $this->assertSame([], $this->decoder->decode(substr($frame, $half, 1)));

        $result = $this->decoder->decode(substr($frame, $half + 1));

        $this->assertCount(1, $result);
        $this->assertEquals($message, $result[0]);
    }

    public function testMultipleMessagesInSingleRead(): void
    {
        $a = new Message(MessageType::REQUEST, '1');
        $b = new Message(MessageType::REQUEST, '2', ['x' => 1]);
        $c = new Message(MessageType::RESPONSE, '3');

        $combined = $this->encoder->encode($a) . $this->encoder->encode($b) . $this->encoder->encode($c);

        $decoded = $this->decoder->decode($combined);

        $this->assertEquals([$a, $b, $c], $decoded);
    }

    public function testLargeMessage(): void
    {
        $payload = str_repeat('x', 100_000);
        $message = new Message(MessageType::REQUEST, 'large', ['data' => $payload]);

        $frame = $this->encoder->encode($message);

        $decoded = $this->decoder->decode($frame);

        $this->assertCount(1, $decoded);
        $this->assertSame($payload, $decoded[0]->payload['data']);
    }

    public function testMessyChunkingProducesAllMessages(): void
    {
        $messages = [];
        for ($i = 0; $i < 10; $i++) {
            $messages[] = new Message(MessageType::REQUEST, "req-$i", ['i' => $i]);
        }

        $stream = '';
        foreach ($messages as $m) {
            $stream .= $this->encoder->encode($m);
        }

        $decoded = [];
        while (strlen($stream) > 0) {
            $chunk = substr($stream, 0, 7);
            $stream = substr($stream, 7);
            $decoded = array_merge($decoded, $this->decoder->decode($chunk));
        }

        $this->assertEquals($messages, $decoded);
    }

    public function testMalformedJsonThrows(): void
    {
        $payload = '{not-valid-json';
        $frame = pack('N', strlen($payload)) . $payload;

        $this->expectException(MalformedMessageException::class);
        $this->decoder->decode($frame);
    }
}
