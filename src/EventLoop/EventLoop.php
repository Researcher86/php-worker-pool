<?php

declare(strict_types=1);

namespace App\EventLoop;

/**
 * Minimal reactor: components register a readable resource plus a callback,
 * and the loop multiplexes a single stream_select() across every registered
 * resource, invoking the matching callback for each one that became readable.
 *
 * A resource can't be used as an array key directly, so every method keys
 * its two maps by `(int) $resource` — PHP's stream resources expose a stable
 * integer id for exactly this purpose, valid for as long as the resource
 * stays open. $resources and $handlers are kept in lockstep by that same id.
 */
final class EventLoop
{
    /** @var array<int, resource> */
    private array $resources = [];

    /** @var array<int, callable(): void> */
    private array $handlers = [];

    /** @param resource $resource */
    public function addReadable(mixed $resource, callable $onReadable): void
    {
        $id = (int) $resource;

        $this->resources[$id] = $resource;
        $this->handlers[$id] = $onReadable;
    }

    /** @param resource $resource */
    public function removeReadable(mixed $resource): void
    {
        $id = (int) $resource;

        unset($this->resources[$id], $this->handlers[$id]);
    }

    public function hasReadable(): bool
    {
        return $this->resources !== [];
    }

    /**
     * Blocks until at least one registered resource becomes readable, then
     * invokes the handler registered for each one that did. Returns
     * immediately without blocking if nothing is currently registered —
     * callers rely on that to know there's nothing left worth waiting for.
     *
     * A resource whose peer disconnected also counts as "readable" here
     * (reading it just returns EOF instead of data), so a dead peer wakes
     * this up rather than blocking forever.
     */
    public function tick(): void
    {
        if ($this->resources === []) {
            return;
        }

        $read = array_values($this->resources);
        $write = [];
        $except = [];

        // A null timeout means block for as long as it takes; every caller
        // here is fine waiting indefinitely for the next event. The `@`
        // suppresses the "Interrupted system call" warning stream_select()
        // raises if a signal arrives mid-call (Master relies on exactly
        // that to wake up and check its shutdown flag on SIGINT/SIGTERM).
        // On that path $read is left unchanged — still every resource, not
        // just the ready ones — since the call never completed; that's
        // harmless here, each handler independently no-ops when there's
        // nothing actually waiting for it.
        @stream_select($read, $write, $except, null);

        // stream_select() rewrites $read in place to keep only the resources
        // that are actually ready, so this only invokes handlers for those
        // (or, on an interrupted call, every resource — see above).
        foreach ($read as $resource) {
            $id = (int) $resource;

            if (isset($this->handlers[$id])) {
                ($this->handlers[$id])();
            }
        }
    }
}
