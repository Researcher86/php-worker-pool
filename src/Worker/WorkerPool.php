<?php

declare(ticks = 1);

namespace App\Worker;

use App\IPC\SocketPair;
use App\Protocol\Message;
use App\Protocol\MessageType;

class WorkerPool
{
    /** @var array<int, WorkerProcess> */
    private array $workers = [];

    public function __construct(int $workerCount)
    {
        for ($i = 0; $i < $workerCount; $i++) {
            $socketPair = new SocketPair();

            $pid = pcntl_fork();
            if ($pid === -1) {
                die('fork failed');
            }

            if ($pid === 0) {
                $socketPair->closeMaster();

                $runner = new WorkerRunner($socketPair->getWorkerSocket());
                $runner->run();

                exit(0);
            }

            $socketPair->closeWorker();

            $this->workers[$pid] = new WorkerProcess($pid, $socketPair->getMasterSocket());
        }
    }

    public function count(): int
    {
        return count($this->workers);
    }

    public function get(): int
    {
        return array_key_last($this->workers);
    }

    public function write(int $workerId, Message $message): void
    {
        $this->workers[$workerId]->write($message);
    }

    /** @return list<Message> */
    public function read(int $workerId): array
    {
        return $this->workers[$workerId]->read();
    }

    /**
     * Sends a batch of requests to the worker and blocks until a response
     * has arrived for every request (matched by id). Returns the responses
     * in the order they matched.
     *
     * @param array<string, Message> $requests map of request id => Message
     *
     * @return list<Message>
     *
     * @throws \App\Protocol\MalformedMessageException
     */
    public function requestBatch(int $workerId, array $requests): array
    {
        foreach ($requests as $request) {
            $this->write($workerId, $request);
        }

        $expected = count($requests);
        $responses = [];

        while (count($responses) < $expected) {
            foreach ($this->read($workerId) as $message) {
                if (isset($requests[$message->id])) {
                    $responses[] = $message;
                }
            }
        }

        return $responses;
    }

    public function stop(): void
    {
        foreach ($this->workers as $worker) {
            $worker->write(new Message(MessageType::SHUTDOWN, 'shutdown-' . $worker->getPid()));
            $worker->close();
        }

        while (pcntl_waitpid(-1, $status) !== -1) {
            // reap all children
        }
    }
}
