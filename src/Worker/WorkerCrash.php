<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * One worker WorkerPool::reapDeadWorkers() found had exited. $lostRequestId
 * is the id it was working on when it died, captured before markDead()
 * clears it - null if it wasn't handling anything.
 */
final readonly class WorkerCrash
{
    public function __construct(
        public WorkerProcess $worker,
        public ?string $lostRequestId,
    ) {
    }
}
