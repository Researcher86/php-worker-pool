<?php

declare(strict_types=1);

namespace PhpWorkerPool\Tests\Worker;

use PHPUnit\Framework\TestCase;
use PhpWorkerPool\Worker\ForkedWorkerLauncher;

/**
 * What a worker inherits from the Master through fork(), and must not keep.
 *
 * Master::run() forks its first workers with every signal it handles
 * blocked (see Master\MasterSignalTest), and later ones with its own handlers
 * installed. Both are inherited: a worker that kept the mask would never
 * act on SIGTERM at all, and one that kept the handler would run Master's
 * closure instead of dying. Either way a worker could no longer be stopped
 * the way WorkerPool stops one that ignores its SHUTDOWN.
 *
 * Of the two resets ForkedWorkerLauncher does, this pins the handler one:
 * on PHP 8.5, pcntl_signal() also unblocks the signal it installs, so the
 * SIG_DFL reset alone already clears SIGTERM from the inherited mask and
 * the explicit SIG_SETMASK after it is belt and braces for the rest.
 */
final class ForkedWorkerLauncherTest extends TestCase
{
    public function testAWorkerForkedWithSignalsBlockedAndHandledStillDiesOnSigterm(): void
    {
        $previousHandler = pcntl_signal_get_handler(SIGTERM);
        $handled = false;

        // This process plays the Master mid-startup: SIGTERM caught by a
        // closure of its own AND blocked, exactly as a worker forked inside
        // Master::run()'s startup section sees it.
        pcntl_signal(SIGTERM, function () use (&$handled): void {
            $handled = true;
        });
        pcntl_sigprocmask(SIG_BLOCK, [SIGTERM], $previousMask);

        try {
            $worker = new ForkedWorkerLauncher()->launch();
        } finally {
            pcntl_sigprocmask(SIG_SETMASK, $previousMask);
            pcntl_signal(SIGTERM, $previousHandler);
        }

        posix_kill($worker->getPid(), SIGTERM);

        $deadline = microtime(true) + 5.0;
        $exited = 0;

        while (($exited = pcntl_waitpid($worker->getPid(), $status, WNOHANG)) === 0 && microtime(true) < $deadline) {
            usleep(10_000);
        }

        if ($exited === 0) {
            posix_kill($worker->getPid(), SIGKILL);
            pcntl_waitpid($worker->getPid(), $status);
            $this->fail('the worker ignored SIGTERM - it kept the inherited mask or handler');
        }

        $worker->close();

        $this->assertTrue(pcntl_wifsignaled($status), 'the worker should have been ended by the signal itself');
        $this->assertSame(SIGTERM, pcntl_wtermsig($status));
        $this->assertFalse($handled, "the Master's handler must not have run in this process either");
    }
}
