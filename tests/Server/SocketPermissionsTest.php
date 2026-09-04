<?php

declare(strict_types=1);

namespace App\Tests\Server;

use App\EventLoop\EventLoop;
use App\Server\UnixSocketServer;
use PHPUnit\Framework\TestCase;

/**
 * The socket is an unauthenticated command channel: whoever can connect can
 * run work on every worker in the pool. Its permissions are the only thing
 * standing in front of that, so they are asserted rather than assumed.
 */
final class SocketPermissionsTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/pwp-perm-' . getmypid() . '-' . uniqid() . '.sock';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    private function mode(): int
    {
        clearstatcache(true, $this->path);

        return (int) (fileperms($this->path) & 0777);
    }

    /**
     * Regression test: the socket used to be created at whatever the umask
     * allowed - 0755 in this project's own container - which let every local
     * account on the machine submit requests to the pool.
     */
    public function testDefaultsToOwnerOnly(): void
    {
        $server = new UnixSocketServer($this->path, new EventLoop(), static function (): void {
        });

        $this->assertSame(0600, $this->mode(), 'the socket must not be reachable by other accounts by default');

        $server->close();
    }

    public function testModeIsConfigurable(): void
    {
        $server = new UnixSocketServer($this->path, new EventLoop(), static function (): void {
        }, mode: 0660);

        $this->assertSame(0660, $this->mode());

        $server->close();
    }

    /**
     * The window between creating the socket and chmod'ing it is closed by
     * lowering the umask first - so even mid-construction it was never more
     * permissive than asked for. Checked by proving the final mode holds
     * with a wide umask in effect, which is the condition that produced the
     * 0755 socket in the first place.
     */
    public function testAWideUmaskDoesNotWidenTheSocket(): void
    {
        $previous = umask(0);

        try {
            $server = new UnixSocketServer($this->path, new EventLoop(), static function (): void {
            });

            $this->assertSame(0600, $this->mode());

            $server->close();
        } finally {
            umask($previous);
        }
    }

    /** A caller's own umask must be left exactly as it was found. */
    public function testUmaskIsRestored(): void
    {
        $before = umask();

        $server = new UnixSocketServer($this->path, new EventLoop(), static function (): void {
        });
        $server->close();

        $this->assertSame($before, umask(), 'constructing a server must not leak its umask change');
    }
}
