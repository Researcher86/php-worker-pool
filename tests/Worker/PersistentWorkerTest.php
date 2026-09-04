<?php

declare(ticks = 1);

namespace App\Tests\Worker;

use App\IPC\SocketPair;
use App\Protocol\Message;
use App\Protocol\MessageType;
use App\Worker\Request;
use App\Worker\Response;
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

    /**
     * The full typed-handler path through a real worker process: the handler
     * declares a DTO parameter, the runtime hydrates each request's payload
     * into it before the call (HandlerAdapter), and a payload that doesn't
     * fit is answered with invalid_payload - distinct from handler_failed,
     * and without the handler ever running or the worker dying.
     */
    public function testDtoTypedHandlerGetsHydratedPayloadAndBadPayloadIsRejected(): void
    {
        $pair = new SocketPair();

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'fork failed');

        if ($pid === 0) {
            $pair->closeMaster();

            $handler = static fn (SumRequest $request): array => [
                'result' => $request->a + $request->b,
            ];

            (new WorkerRunner($pair->getWorkerSocket(), $handler))->run();

            exit(0);
        }

        $pair->closeWorker();
        $master = $pair->getMasterSocket();

        // Hydrates into SumRequest(a: 10, b: 20) - 'extra' is ignored.
        $master->write(new Message(MessageType::REQUEST, 'sum-1', ['a' => 10, 'b' => 20, 'extra' => true]));

        $response = $master->read()[0];
        $this->assertSame(MessageType::RESPONSE, $response->type);
        $this->assertSame(['result' => 30], $response->payload);

        // Missing required 'b' - rejected before the handler runs.
        $master->write(new Message(MessageType::REQUEST, 'sum-2', ['a' => 10]));

        $error = $master->read()[0];
        $this->assertSame(MessageType::ERROR, $error->type);
        $this->assertSame('sum-2', $error->id);
        $this->assertSame(['error' => 'invalid_payload'], $error->payload);

        // The same worker is still alive and serving.
        $master->write(new Message(MessageType::REQUEST, 'sum-3', ['a' => 1, 'b' => 2]));
        $this->assertSame(['result' => 3], $master->read()[0]->payload);

        $master->write(new Message(MessageType::SHUTDOWN, 'shutdown-1'));
        $master->close();

        pcntl_waitpid($pid, $status);
        $this->assertTrue(pcntl_wifexited($status));
    }

    /**
     * A handler that returns Response::error() answers with an ERROR message
     * carrying the code IT chose - the deliberate-failure path, as opposed to
     * throwing (handler_failed) or a payload that doesn't fit
     * (invalid_payload). The worker keeps serving afterwards either way.
     */
    public function testHandlerReturnedErrorResponseBecomesAnErrorMessage(): void
    {
        $pair = new SocketPair();

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'fork failed');

        if ($pid === 0) {
            $pair->closeMaster();

            $handler = static fn (Request $request): Response => match ($request->action) {
                'ping' => new Response(['pong' => true]),
                default => Response::error('unknown_action'),
            };

            (new WorkerRunner($pair->getWorkerSocket(), $handler))->run();

            exit(0);
        }

        $pair->closeWorker();
        $master = $pair->getMasterSocket();

        $master->write(new Message(MessageType::REQUEST, 'act-1', ['action' => 'nope']));

        $error = $master->read()[0];
        $this->assertSame(MessageType::ERROR, $error->type);
        $this->assertSame('act-1', $error->id);
        $this->assertSame(['error' => 'unknown_action'], $error->payload);

        // A known action on the same worker still answers normally.
        $master->write(new Message(MessageType::REQUEST, 'act-2', ['action' => 'ping']));

        $response = $master->read()[0];
        $this->assertSame(MessageType::RESPONSE, $response->type);
        $this->assertSame(['pong' => true], $response->payload);

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
/** Application-style DTO for the typed-handler round-trip test above. */
final readonly class SumRequest
{
    public function __construct(
        public int $a,
        public int $b,
    ) {
    }
}
