<?php

declare(strict_types=1);

namespace App\Tests\Worker;

use App\IPC\Socket;
use App\Worker\WorkerProcess;
use App\Worker\WorkerState;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every cell of WorkerProcess::TRANSITIONS, asserted from the outside.
 *
 * The table is the specification; this is the proof that the implementation
 * matches it, cell by cell, including the illegal ones. A state added later
 * without answering for every event fails here rather than in production,
 * which is the entire reason the table is declarative.
 */
final class StateTransitionMatrixTest extends TestCase
{
    /** @return array<string, array{WorkerState, string, WorkerState|null}> */
    public static function matrix(): array
    {
        // [from, event, expected state] - null means "must throw".
        $cells = [
            [WorkerState::STARTING, 'ready', WorkerState::IDLE],
            [WorkerState::STARTING, 'dispatch', null],
            [WorkerState::STARTING, 'respond', null],
            [WorkerState::STARTING, 'drain', WorkerState::DRAINING],
            [WorkerState::STARTING, 'stop', WorkerState::STOPPING],
            [WorkerState::STARTING, 'die', WorkerState::DEAD],

            [WorkerState::IDLE, 'ready', null],
            [WorkerState::IDLE, 'dispatch', WorkerState::BUSY],
            [WorkerState::IDLE, 'respond', null],
            [WorkerState::IDLE, 'drain', WorkerState::DRAINING],
            [WorkerState::IDLE, 'stop', WorkerState::STOPPING],
            [WorkerState::IDLE, 'die', WorkerState::DEAD],

            [WorkerState::BUSY, 'ready', null],
            [WorkerState::BUSY, 'dispatch', null],
            [WorkerState::BUSY, 'respond', WorkerState::IDLE],
            [WorkerState::BUSY, 'drain', WorkerState::DRAINING],
            [WorkerState::BUSY, 'stop', WorkerState::STOPPING],
            [WorkerState::BUSY, 'die', WorkerState::DEAD],

            [WorkerState::DRAINING, 'ready', WorkerState::DRAINING],
            [WorkerState::DRAINING, 'dispatch', null],
            [WorkerState::DRAINING, 'respond', WorkerState::DRAINING],
            [WorkerState::DRAINING, 'drain', WorkerState::DRAINING],
            [WorkerState::DRAINING, 'stop', WorkerState::STOPPING],
            [WorkerState::DRAINING, 'die', WorkerState::DEAD],

            [WorkerState::STOPPING, 'ready', null],
            [WorkerState::STOPPING, 'dispatch', null],
            [WorkerState::STOPPING, 'respond', null],
            [WorkerState::STOPPING, 'drain', WorkerState::STOPPING],
            [WorkerState::STOPPING, 'stop', WorkerState::STOPPING],
            [WorkerState::STOPPING, 'die', WorkerState::DEAD],

            [WorkerState::DEAD, 'ready', WorkerState::DEAD],
            [WorkerState::DEAD, 'dispatch', null],
            [WorkerState::DEAD, 'respond', WorkerState::DEAD],
            [WorkerState::DEAD, 'drain', WorkerState::DEAD],
            [WorkerState::DEAD, 'stop', null],
            [WorkerState::DEAD, 'die', WorkerState::DEAD],
        ];

        $named = [];
        foreach ($cells as [$from, $event, $to]) {
            $named[sprintf('%s + %s', $from->name, $event)] = [$from, $event, $to];
        }

        return $named;
    }

    #[DataProvider('matrix')]
    public function testEveryCellOfTheTable(WorkerState $from, string $event, ?WorkerState $expected): void
    {
        $worker = $this->workerIn($from);

        if ($expected === null) {
            $this->expectException(LogicException::class);
            $this->fire($worker, $event);

            return;
        }

        $this->fire($worker, $event);
        $this->assertSame($expected, $worker->getState());
    }

    /**
     * The table covers every state - a state added without a row here
     * fails this test, which is the point of asserting the matrix rather
     * than a handful of paths.
     */
    public function testEveryStateAppearsInTheMatrix(): void
    {
        $covered = [];
        foreach (self::matrix() as [$from, , ]) {
            $covered[$from->name] = true;
        }

        foreach (WorkerState::cases() as $state) {
            $this->assertArrayHasKey($state->name, $covered, $state->name . ' has no rows in the transition matrix');
        }
    }

    /** BUSY is two transitions away now: STARTING -> IDLE -> BUSY. */
    private function driveTo(WorkerProcess $worker, string $requestId): void
    {
        $worker->markReady();
        $worker->beginRequest($requestId);
    }

    private function fire(WorkerProcess $worker, string $event): void
    {
        match ($event) {
            'ready' => $worker->markReady(),
            'dispatch' => $worker->beginRequest('req-x'),
            'respond' => $worker->finishRequest(),
            'drain' => $worker->drain(),
            'stop' => $worker->stop(),
            'die' => $worker->markDead(),
        };
    }

    /** Drives a fresh worker into $state using only legal transitions. */
    private function workerIn(WorkerState $state): WorkerProcess
    {
        [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fclose($b);

        $worker = new WorkerProcess(90_000, new Socket($a));

        match ($state) {
            // A fresh worker is STARTING - that is the one state with
            // nothing to drive, and everything else is reached through it.
            WorkerState::STARTING => null,
            WorkerState::IDLE => $worker->markReady(),
            WorkerState::BUSY => $this->driveTo($worker, 'req-1'),
            WorkerState::DRAINING => $worker->drain(),
            WorkerState::STOPPING => $worker->stop(),
            WorkerState::DEAD => $worker->markDead(),
        };

        $this->assertSame($state, $worker->getState(), 'setup failed to reach ' . $state->name);

        return $worker;
    }
}
