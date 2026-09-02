<?php

declare(strict_types=1);

namespace App\Worker;

use App\IPC\Socket;
use App\Protocol\Message;

final class WorkerProcess
{
    private const array AVAILABLE_STATES = [WorkerState::STARTING, WorkerState::IDLE];

    public function __construct(
        private readonly int $pid,
        private readonly Socket $socket,
        private WorkerState $state = WorkerState::STARTING,
        private ?string $currentRequestId = null,
    ) {
    }

    public function getPid(): int
    {
        return $this->pid;
    }

    public function getState(): WorkerState
    {
        return $this->state;
    }

    public function getCurrentRequestId(): ?string
    {
        return $this->currentRequestId;
    }

    /**
     * Whether the worker can currently accept a request or be stopped
     * (it has not been dispatched to, or is done with its last request).
     */
    public function isAvailable(): bool
    {
        return in_array($this->state, self::AVAILABLE_STATES, true);
    }

    /**
     * Marks the worker as busy with the given request, then returns
     * IDLE once the request has finished.
     */
    public function beginRequest(string $requestId): void
    {
        $this->assertTransitions(self::AVAILABLE_STATES);

        $this->currentRequestId = $requestId;
        $this->state = WorkerState::BUSY;
    }

    public function finishRequest(): void
    {
        $this->assertTransitions([WorkerState::BUSY]);

        $this->currentRequestId = null;
        $this->state = WorkerState::IDLE;
    }

    public function stop(): void
    {
        $this->assertTransitions(self::AVAILABLE_STATES);

        $this->state = WorkerState::STOPPING;
    }

    public function markDead(): void
    {
        $this->state = WorkerState::DEAD;
        $this->currentRequestId = null;
    }

    /** @param list<WorkerState> $allowed */
    private function assertTransitions(array $allowed): void
    {
        if (!in_array($this->state, $allowed, true)) {
            throw new \LogicException(
                sprintf('Illegal transition from state %s', $this->state->name)
            );
        }
    }

    public function write(Message $message): void
    {
        $this->socket->write($message);
    }

    /** @return list<Message> */
    public function readAvailable(): array
    {
        return $this->socket->readAvailable();
    }

    public function close(): void
    {
        $this->socket->close();
    }
}
