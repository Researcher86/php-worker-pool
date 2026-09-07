<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Protocol\MessageType;
use App\Worker\WorkerPool;

/**
 * Drives the readiness handshake by hand, for tests that hold a WorkerPool
 * with real forked workers but no Dispatcher.
 *
 * In a running Master the Dispatcher does this: it watches every worker's
 * socket and calls markReady() when READY arrives. A test that only wants a
 * pool has nobody listening, so its workers would sit in STARTING forever
 * and getAvailable() would keep returning null - which looks exactly like a
 * hang, and is why this helper exists rather than each test growing its own
 * sleep loop.
 */
trait AwaitsReadyWorkers
{
    /** Blocks until every worker in $pool has reported READY. */
    private function awaitReadyWorkers(WorkerPool $pool, float $timeoutSeconds = 5.0): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $starting = 0;

            foreach ($pool->all() as $pid => $worker) {
                if (!$worker->isStarting()) {
                    continue;
                }

                $starting++;

                foreach ($worker->readAvailable() as $message) {
                    if ($message->type === MessageType::READY) {
                        $pool->markReady($pid);
                    }
                }
            }

            if ($starting === 0) {
                return;
            }

            usleep(1_000);
        }

        $this->fail(sprintf('workers were still STARTING after %.1fs', $timeoutSeconds));
    }
}
