<?php

declare(strict_types=1);

namespace PhpWorkerPool\Tests\Master;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PhpWorkerPool\Master\Master;
use PhpWorkerPool\Support\Clock;
use PhpWorkerPool\Support\NullLogger;
use PhpWorkerPool\Tests\Support\SignalMaskProbeClock;
use PhpWorkerPool\Tests\Support\SignalOnFirstCallClock;
use PhpWorkerPool\Worker\Telemetry\SharedTelemetry;
use Throwable;

/**
 * When the Master's signals may reach it, and when they may not.
 *
 * First, a signal that lands while Master::run() is still starting up: after the
 * first worker has been forked and the telemetry segment created, before
 * the signal handlers exist. Left to the default action, SIGTERM (or
 * SIGINT, or SIGHUP) kills the Master right there - orphaning the workers,
 * stranding the shared memory segment, and never getting as far as the
 * code that would have cleaned either up.
 *
 * Hitting that window by timing a kill from outside would be luck. The
 * Clock is the seam instead: WorkerPool stamps a freshly launched worker
 * with clock->now() from inside its constructor, so a Clock that signals its
 * own process on the first call delivers the signal at exactly that point,
 * every run. The Master runs in a forked child, since a default-action
 * SIGTERM is exactly the outcome being guarded against and must not take
 * PHPUnit down with it.
 *
 * Second, a signal that lands while the Master's own code is running. PHP
 * calls a signal handler between two VM instructions, and if those happen
 * to be mid-throw - an exception on its way to its catch, which the Master
 * has every time a worker or a client disappears - the handler is silently
 * skipped, and so is every other signal queued with it. The only safe
 * place to take a signal is where nothing can be throwing: the loop's wait.
 */
final class MasterSignalTest extends TestCase
{
    private string $socketPath = '';

    private ?int $masterPid = null;

    protected function setUp(): void
    {
        $this->socketPath = sys_get_temp_dir() . '/pwp-startup-' . getmypid() . '-' . uniqid() . '.sock';
    }

    protected function tearDown(): void
    {
        // A Master that outlived its assertions is not left running past
        // the test that started it.
        if ($this->masterPid !== null && pcntl_waitpid($this->masterPid, $status, WNOHANG) === 0) {
            posix_kill($this->masterPid, SIGKILL);
            pcntl_waitpid($this->masterPid, $status);
        }

        @unlink($this->socketPath);

        // A Master killed mid-startup leaves its segment and the anchor
        // naming it - reclaim both so a failing run doesn't leak them.
        if (file_exists($this->socketPath . '.telemetry')) {
            SharedTelemetry::openAt($this->socketPath . '.telemetry', 1)->destroy();
        }
    }

    /** @return iterable<string, array{int}> */
    public static function shutdownSignals(): iterable
    {
        yield 'SIGTERM' => [SIGTERM];
        yield 'SIGINT' => [SIGINT];
    }

    /**
     * Not lost and not fatal: held until the handler exists, then acted on -
     * the Master shuts down through its own cleanup and exits normally.
     */
    #[DataProvider('shutdownSignals')]
    public function testAShutdownSignalDuringStartupIsAShutdownNotADeath(int $signal): void
    {
        $pid = $this->forkMaster(new SignalOnFirstCallClock($signal));

        $status = $this->waitForExit($pid, 10.0);

        $this->assertNotNull($status, 'the Master should have shut itself down');
        $this->assertFalse(pcntl_wifsignaled($status), sprintf(
            'the Master was killed by signal %d instead of handling it',
            pcntl_wifsignaled($status) ? pcntl_wtermsig($status) : 0,
        ));
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));

        $this->assertFileDoesNotExist($this->socketPath, 'the socket file must be removed');
        $this->assertFileDoesNotExist(
            $this->socketPath . '.telemetry',
            'the telemetry segment must be destroyed - its anchor is removed only once it is'
        );
    }

    /**
     * SIGHUP's default action is to terminate too. Mid-startup it must be a
     * reload request like any other, not the end of the process.
     */
    public function testAReloadSignalDuringStartupDoesNotKillTheMaster(): void
    {
        $pid = $this->forkMaster(new SignalOnFirstCallClock(SIGHUP));

        $this->assertNull($this->waitForExit($pid, 1.0), 'SIGHUP during startup must not end the Master');

        posix_kill($pid, SIGTERM);
        $status = $this->waitForExit($pid, 10.0);

        $this->assertNotNull($status, 'the Master should still shut down on SIGTERM afterwards');
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertFileDoesNotExist($this->socketPath);
    }

    /**
     * The Clock is read throughout the main loop's own code, so a probe
     * there sees what that code runs with: SIGTERM must be blocked every
     * time - deliverable only inside EventLoop::tick()'s wait - and still
     * arrive when sent.
     */
    public function testTheMainLoopRunsItsOwnCodeWithShutdownSignalsBlocked(): void
    {
        $clock = new SignalMaskProbeClock(SIGTERM);
        $report = $this->socketPath . '.report';

        $pid = $this->forkMaster($clock, static function () use ($clock, $report): void {
            file_put_contents($report, json_encode([$clock->readings, $clock->readingsWithSignalUnblocked]));
        });

        // Long enough for a few idle ticks of the loop, not just startup.
        usleep(1_500_000);
        posix_kill($pid, SIGTERM);

        $status = $this->waitForExit($pid, 10.0);

        $this->assertNotNull($status, 'SIGTERM must still reach the Master through the loop\'s wait');
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));

        [$readings, $unblocked] = json_decode((string) file_get_contents($report), true);
        @unlink($report);

        $this->assertGreaterThan(0, $readings, 'the probe must actually have been read');
        $this->assertSame(0, $unblocked, sprintf(
            '%d of %d clock readings happened with SIGTERM deliverable in the middle of the Master\'s own code',
            $unblocked,
            $readings,
        ));
    }

    /** @param (callable(): void)|null $afterRun runs in the child once run() has returned */
    private function forkMaster(Clock $clock, ?callable $afterRun = null): int
    {
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'fork failed');

        if ($pid === 0) {
            // Child: never returns into PHPUnit.
            try {
                new Master(
                    socketPath: $this->socketPath,
                    minWorkers: 2,
                    maxWorkers: 4,
                    gracefulShutdownTimeoutSeconds: 5.0,
                    logger: new NullLogger(),
                    clock: $clock,
                )->run();

                if ($afterRun !== null) {
                    $afterRun();
                }
            } catch (Throwable) {
                exit(1);
            }

            exit(0);
        }

        $this->masterPid = $pid;

        return $pid;
    }

    /** @return int|null the wait status, or null if it was still running at the deadline */
    private function waitForExit(int $pid, float $timeoutSeconds): ?int
    {
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            if (pcntl_waitpid($pid, $status, WNOHANG) === $pid) {
                return $status;
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        return null;
    }
}
