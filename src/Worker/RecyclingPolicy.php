<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * When a persistent worker has done enough and should be replaced by a fresh
 * process.
 *
 * A long-running PHP process accumulates what a per-request runtime throws
 * away for free: leaked memory, static and singleton state, cached
 * connections that have quietly gone stale, opcache/GC pressure. Recycling
 * is the standard answer - php-fpm's `pm.max_requests`, RoadRunner's
 * `max_jobs` / `max_memory` - and the reason those exist is that even a
 * correct handler leaks eventually, because the libraries under it do.
 *
 * A worker over its limits is DRAINED, never killed: it finishes the request
 * it is holding, answers it normally, and only then exits, with a
 * replacement already launched. Nothing in flight is lost, so a limit can be
 * set aggressively without costing a single failed request.
 *
 * Every limit is optional; null means "don't check this one".
 */
final readonly class RecyclingPolicy
{
    public function __construct(
        // Replace after this many completed requests.
        public ?int $maxRequests = null,
        // Replace once the process is this old, in seconds.
        public ?float $maxLifetime = null,
        // Replace once the worker reports using more than this (see WorkerMemory).
        public ?int $maxMemoryBytes = null,
    ) {
    }

    /** A policy that never recycles - the default, so nothing changes unless asked. */
    public static function disabled(): self
    {
        return new self();
    }

    public function isEnabled(): bool
    {
        return $this->maxRequests !== null
            || $this->maxLifetime !== null
            || $this->maxMemoryBytes !== null;
    }

    /**
     * The reason $worker should be recycled, or null to keep it.
     *
     * Returns a reason string rather than a bool so the log line can say
     * WHY a worker was replaced - "hit maxRequests" and "hit maxMemory" call
     * for very different follow-up.
     */
    public function exhaustedReason(WorkerProcess $worker, float $now, ?int $memoryBytes): ?string
    {
        if ($this->maxRequests !== null && $worker->getHandledRequests() >= $this->maxRequests) {
            return sprintf('handled %d requests (max %d)', $worker->getHandledRequests(), $this->maxRequests);
        }

        if ($this->maxLifetime !== null && $worker->getAgeSeconds($now) >= $this->maxLifetime) {
            return sprintf('lived %.0fs (max %.0fs)', $worker->getAgeSeconds($now), $this->maxLifetime);
        }

        // $memoryBytes is null where the platform can't report it (see
        // WorkerMemory) - an unmeasurable limit is simply not enforced,
        // rather than guessed at.
        if ($this->maxMemoryBytes !== null && $memoryBytes !== null && $memoryBytes >= $this->maxMemoryBytes) {
            return sprintf('used %d bytes of memory (max %d)', $memoryBytes, $this->maxMemoryBytes);
        }

        return null;
    }
}
