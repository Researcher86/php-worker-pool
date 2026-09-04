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

    /**
     * A handler that throws must answer the request with an error rather
     * than kill the worker: crashing would cost the Master a reap-and-refork
     * and turn one bad request into worker_crashed, when an error reply
     * answers it just as definitively - and the same warm worker keeps
     * serving the very next request.
     *
     * The handler is application-level (injected, same as bin/server.php
     * does) - the same calculate route the server configures, whose `a + b`
     * throws a TypeError on non-numeric params.
     */
    public function testHandlerFailureAnswersWithAnErrorAndTheWorkerSurvives(): void
    {
        $pair = new SocketPair();

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'fork failed');

        if ($pid === 0) {
            $pair->closeMaster();

            $calculate = static fn (array $payload): array => [
                'result' => $payload['params']['a'] + $payload['params']['b'],
            ];

            (new WorkerRunner($pair->getWorkerSocket(), $calculate))->run();

            exit(0);
        }

        $pair->closeWorker();
        $master = $pair->getMasterSocket();

        // Non-numeric operands make calculate's `a + b` throw a TypeError.
        $master->write(new Message(MessageType::REQUEST, 'bad-1', [
            'action' => 'calculate',
            'params' => ['a' => 'x', 'b' => 'y'],
        ]));

        $error = $master->read()[0];
        $this->assertSame(MessageType::ERROR, $error->type);
        $this->assertSame('bad-1', $error->id);
        $this->assertSame(['error' => 'handler_failed'], $error->payload);

        // Same worker, next request - still alive and answering normally.
        $master->write(new Message(MessageType::REQUEST, 'good-1', [
            'action' => 'calculate',
            'params' => ['a' => 2, 'b' => 3],
        ]));

        $response = $master->read()[0];
        $this->assertSame(MessageType::RESPONSE, $response->type);
        $this->assertSame(['result' => 5], $response->payload);

        $master->write(new Message(MessageType::SHUTDOWN, 'shutdown-1'));
        $master->close();

        pcntl_waitpid($pid, $status);
        $this->assertTrue(pcntl_wifexited($status));
    }

    public function testWorkerExitsCleanlyWhenMasterClosesConnectionWithoutShutdown(): void
    {
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
        $pair->getMasterSocket()->close();

        pcntl_waitpid($pid, $status);

        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
    }
}