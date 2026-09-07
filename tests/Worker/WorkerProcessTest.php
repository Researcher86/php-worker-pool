<?php

declare(strict_types=1);

namespace App\Tests\Worker;

use App\IPC\Socket;
use App\Worker\WorkerProcess;
use App\Worker\WorkerState;
use LogicException;
use PHPUnit\Framework\TestCase;

final class WorkerProcessTest extends TestCase
{
    private WorkerProcess $worker;

    protected function setUp(): void
    {
        $socket = fopen('php://memory', 'r+');
        $this->assertNotFalse($socket);

        $this->worker = new WorkerProcess(42, new Socket($socket));
    }

    public function testStartsStartingAndNotYetDispatchable(): void
    {
        $this->assertSame(WorkerState::STARTING, $this->worker->getState());
        $this->assertSame(42, $this->worker->getPid());
        $this->assertNull($this->worker->getCurrentRequestId());
        $this->assertFalse($this->worker->isAvailable(), 'a worker is not dispatchable before it reports ready');
    }

    /**
     * The point of the state: a fork is not a usable worker. Until the
     * process says READY, the table itself refuses to dispatch here - it is
     * not a check a caller has to remember to make.
     */
    public function testDispatchingBeforeReadyThrows(): void
    {
        $this->expectException(LogicException::class);
        $this->worker->beginRequest('req-1');
    }

    public function testMarkReadyMakesItDispatchable(): void
    {
        $this->worker->markReady();

        $this->assertSame(WorkerState::IDLE, $this->worker->getState());
        $this->assertTrue($this->worker->isAvailable());
        $this->assertFalse($this->worker->isStarting());
    }

    /** Readiness is a fact about the process, established once. */
    public function testMarkReadyTwiceThrows(): void
    {
        $this->worker->markReady();

        $this->expectException(LogicException::class);
        $this->worker->markReady();
    }

    public function testBeginRequestMovesToBusy(): void
    {
        $this->worker->markReady();
        $this->worker->beginRequest('req-1');

        $this->assertSame(WorkerState::BUSY, $this->worker->getState());
        $this->assertSame('req-1', $this->worker->getCurrentRequestId());
    }

    public function testFinishRequestReturnsToIdle(): void
    {
        $this->worker->markReady();
        $this->worker->beginRequest('req-1');
        $this->worker->finishRequest();

        $this->assertSame(WorkerState::IDLE, $this->worker->getState());
        $this->assertNull($this->worker->getCurrentRequestId());
    }

    public function testBeginRequestFromBusyThrows(): void
    {
        $this->worker->markReady();
        $this->worker->beginRequest('req-1');

        $this->expectException(LogicException::class);
        $this->worker->beginRequest('req-2');
    }

    public function testMarkDeadClearsCurrentRequest(): void
    {
        $this->worker->markReady();
        $this->worker->beginRequest('req-1');
        $this->worker->markDead();

        $this->assertSame(WorkerState::DEAD, $this->worker->getState());
        $this->assertNull($this->worker->getCurrentRequestId());
    }

    public function testIsAvailableReflectsState(): void
    {
        $this->assertFalse($this->worker->isAvailable(), 'STARTING is not available');

        $this->worker->markReady();
        $this->assertTrue($this->worker->isAvailable());

        $this->worker->beginRequest('req-1');
        $this->assertFalse($this->worker->isAvailable());

        $this->worker->finishRequest();
        $this->assertTrue($this->worker->isAvailable());

        $this->worker->stop();
        $this->assertFalse($this->worker->isAvailable());
    }

    public function testStopFromStartingMovesToStopping(): void
    {
        $this->worker->stop();

        $this->assertSame(WorkerState::STOPPING, $this->worker->getState());
    }

    public function testStopFromBusyAbandonsInFlightRequest(): void
    {
        $this->worker->markReady();
        $this->worker->beginRequest('req-1');

        $this->worker->stop();

        $this->assertSame(WorkerState::STOPPING, $this->worker->getState());
        $this->assertNull($this->worker->getCurrentRequestId());
    }

    public function testStopFromDeadThrows(): void
    {
        $this->worker->markDead();

        $this->expectException(LogicException::class);
        $this->worker->stop();
    }
}
