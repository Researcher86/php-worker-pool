<?php

declare(strict_types=1);

namespace App\Tests\Server;

use App\EventLoop\EventLoop;
use App\Server\UnixSocketServer;
use PHPUnit\Framework\TestCase;

final class UnixSocketServerTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = '/tmp/php-worker-pool-' . getmypid() . '.sock';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testBindsUnixSocketAndAcceptsClient(): void
    {
        $loop = new EventLoop();
        $accepted = null;

        $server = new UnixSocketServer(
            $this->path,
            $loop,
            function (mixed $client) use (&$accepted): void {
                $accepted = $client;
            }
        );

        $this->assertFileExists($this->path);
        $this->assertTrue($loop->hasReadable());

        $client = stream_socket_client('unix://' . $this->path);
        $this->assertNotFalse($client);

        $loop->tick();

        $this->assertNotNull($accepted);
        $this->assertIsResource($accepted);

        $server->close();
        fclose($client);
    }

    public function testRemoveSocketFileOnClose(): void
    {
        $loop = new EventLoop();
        $server = new UnixSocketServer($this->path, $loop, static function (): void {
        });

        $this->assertFileExists($this->path);

        $server->close();

        $this->assertFileDoesNotExist($this->path);
    }
}
