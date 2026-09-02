<?php

declare(ticks = 1);

namespace App\Tests\Worker;

use App\IPC\SocketPair;
use App\Worker\WorkerRunner;
use PHPUnit\Framework\TestCase;

final class PersistentWorkerTest extends TestCase
{
    public function testWorkerProcessesMultipleConsecutiveRequests(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension required');
        }

        $pair = new SocketPair();

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'fork failed');

        if ($pid === 0) {
            $pair->closeMaster();

            $runner = new WorkerRunner($pair->getWorkerSocket());
            $runner->run();

            exit(0);
        }

        $pair->closeWorker();
        $master = $pair->getMasterSocket();

        $requests = 100;

        for ($i = 0; $i < $requests; $i++) {
            $master->write("PING #{$i}\n");
        }

        for ($i = 0; $i < $requests; $i++) {
            $response = $master->read();
            $this->assertNotSame(false, $response, "no response for request #{$i}");
            $this->assertStringContainsString('PONG', $response);
        }

        $master->write("STOP\n");
        $master->close();

        pcntl_waitpid($pid, $status);

        $this->assertTrue(pcntl_wifexited($status));
    }
}
