<?php

declare(ticks = 1);

namespace App\Tests\Worker;

use App\IPC\SocketPair;
use App\Protocol\Message;
use App\Protocol\MessageType;
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
            $master->write(new Message(MessageType::REQUEST, "ping-$i"));
        }

        $received = 0;
        while ($received < $requests) {
            foreach ($master->read() as $message) {
                $this->assertSame(MessageType::RESPONSE, $message->type);
                $this->assertSame("ping-$received", $message->id);
                $received++;
            }
        }

        $master->write(new Message(MessageType::SHUTDOWN, 'shutdown-1'));
        $master->close();

        pcntl_waitpid($pid, $status);

        $this->assertTrue(pcntl_wifexited($status));
    }
}