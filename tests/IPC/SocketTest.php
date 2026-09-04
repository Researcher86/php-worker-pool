<?php

declare(strict_types=1);

namespace App\Tests\IPC;

use App\IPC\ConnectionClosedException;
use App\IPC\Socket;
use App\Protocol\Message;
use App\Protocol\MessageType;
use PHPUnit\Framework\TestCase;

final class SocketTest extends TestCase
{
    public function testWriteAndReadRoundTripsASimpleMessage(): void
    {
        [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $writer = new Socket($a);
        $reader = new Socket($b);

        $writer->write(new Message(MessageType::REQUEST, 'req-1', ['x' => 1]));
        $messages = $reader->read();

        $this->assertCount(1, $messages);
        $this->assertSame('req-1', $messages[0]->id);
        $this->assertSame(['x' => 1], $messages[0]->payload);
    }

    /**
     * Regression test: write() used to do a single unchecked fwrite(), so a
     * message larger than the socket's kernel send buffer got silently
     * truncated on a non-blocking socket instead of being retried - the
     * receiving end would see a framing desync (or, here, the read would
     * simply never get the full frame). Forces exactly that by writing a
     * message far bigger than any realistic default buffer to a
     * non-blocking socket while nothing drains it for a while.
     */
    public function testWriteRetriesUntilTheFullMessageIsWrittenPastTheSendBuffer(): void
    {
        [$writeEnd, $readEnd] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($writeEnd, false);

        // Comfortably bigger than any realistic default socket send buffer,
        // but still under MessageDecoder's own size limit (1 MiB).
        $blob = str_repeat('x', 700_000);

        $pid = pcntl_fork();
        $this->assertNotFalse($pid);

        if ($pid === 0) {
            // Child: a deliberately slow reader - by the time it starts
            // draining, the parent's writes should already have filled the
            // send buffer and be blocked on retrying via write()'s
            // stream_select() wait.
            fclose($writeEnd);
            usleep(200_000);

            $reader = new Socket($readEnd);
            $messages = $reader->read();

            exit($messages !== [] && $messages[0]->payload['blob'] === $blob ? 0 : 1);
        }

        fclose($readEnd);

        $writer = new Socket($writeEnd);
        $writer->write(new Message(MessageType::REQUEST, 'req-1', ['blob' => $blob]));
        $writer->close();

        pcntl_waitpid($pid, $status);

        $this->assertSame(0, pcntl_wexitstatus($status), 'child did not receive the full, untruncated message');
    }

    /**
     * The other half of the same fix: a peer that never drains at all (not
     * just slow) must not block write() - and this codebase's write() -
     * forever. It gives up silently past writeTimeoutSeconds, the same as
     * it always has for a peer that's outright gone.
     */
    public function testWriteGivesUpWithoutThrowingWhenThePeerNeverDrains(): void
    {
        [$writeEnd, $readEnd] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($writeEnd, false);

        $writer = new Socket($writeEnd, writeTimeoutSeconds: 0.2);

        $start = microtime(true);
        $writer->write(new Message(MessageType::REQUEST, 'req-1', ['blob' => str_repeat('x', 2_000_000)]));
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(2.0, $elapsed, 'write() should give up around writeTimeoutSeconds, not hang');

        fclose($readEnd);
        $writer->close();
    }

    /**
     * Once write() gives up on a frame partway through, the stream is stuck
     * mid-frame - nothing written after it could ever be framed correctly by
     * the peer's decoder. Giving up used to be silent: the NEXT write went
     * out anyway and was parsed as the rest of the abandoned frame. Now the
     * socket marks itself broken - later writes are dropped, and the sending
     * side is shut down so the peer sees clean EOF, never a garbled frame.
     */
    public function testAGivenUpWriteBreaksTheSocketInsteadOfDesyncingTheStream(): void
    {
        [$writeEnd, $readEnd] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($writeEnd, false);

        // A timeout so small the write gives up as soon as the kernel send
        // buffer fills (nothing is draining the other end yet).
        $writer = new Socket($writeEnd, writeTimeoutSeconds: 0.001);

        // Bigger than the kernel send buffer: gives up mid-frame.
        $writer->write(new Message(MessageType::REQUEST, 'req-1', ['blob' => str_repeat('x', 700_000)]));

        // Must be silently dropped, NOT appended after the unfinished frame.
        $writer->write(new Message(MessageType::REQUEST, 'req-2', ['x' => 1]));

        // The reader drains what did arrive: the only frame ever started is
        // incomplete, so it must decode nothing and then see clean EOF.
        $reader = new Socket($readEnd);
        $decoded = [];

        try {
            while (true) {
                $decoded = [...$decoded, ...$reader->read()];
            }
        } catch (ConnectionClosedException) {
            // clean EOF - exactly what a broken writer should look like
        }

        $this->assertSame([], $decoded);

        $writer->close();
        $reader->close();
    }
}
