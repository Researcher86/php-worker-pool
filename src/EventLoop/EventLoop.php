<?php

declare(strict_types=1);

namespace App\EventLoop;

/**
 * Minimal reactor: components register a readable (or writable) resource plus
 * a callback, and the loop multiplexes a single stream_select() across every
 * registered resource, invoking the matching callback for each one that
 * became ready.
 *
 * A resource can't be used as an array key directly, so every method keys
 * its maps by `(int) $resource` — PHP's stream resources expose a stable
 * integer id for exactly this purpose, valid for as long as the resource
 * stays open. Each direction keeps two maps in lockstep by that same id:
 * readResources is paired with readHandlers, writeResources with
 * writeHandlers — register writes to both, deregister unsets from both.
 */
final class EventLoop
{
    /** @var array<int, resource> */
    private array $readResources = [];

    /** @var array<int, callable(): void> */
    private array $readHandlers = [];

    /** @var array<int, resource> */
    private array $writeResources = [];

    /** @var array<int, callable(): void> */
    private array $writeHandlers = [];

    /**
     * Drops resources that were closed by whoever owns them, before
     * stream_select() is handed one and raises a TypeError that takes the
     * whole process down.
     *
     * The loop does not own what it watches, and an owner may close at a
     * moment the loop cannot observe: WorkerPool closes a retiring worker's
     * socket while the worker stays in the pool until SIGCHLD reaps it, a
     * client connection is dropped from inside a handler, a shutdown closes
     * everything at once. Requiring every one of them to deregister first -
     * in the right order, on every path, including the ones that throw - is
     * a coupling the loop can simply not need: a closed resource is never
     * going to be ready again, so forgetting it is always right.
     */
    private function forgetClosedResources(): void
    {
        foreach ($this->readResources as $id => $resource) {
            if (!is_resource($resource)) {
                unset($this->readResources[$id], $this->readHandlers[$id]);
            }
        }

        foreach ($this->writeResources as $id => $resource) {
            if (!is_resource($resource)) {
                unset($this->writeResources[$id], $this->writeHandlers[$id]);
            }
        }
    }

    /** @param resource $resource */
    public function addReadable(mixed $resource, callable $onReadable): void
    {
        $id = (int) $resource;

        $this->readResources[$id] = $resource;
        $this->readHandlers[$id] = $onReadable;
    }

    /** @param resource $resource */
    public function removeReadable(mixed $resource): void
    {
        $id = (int) $resource;

        unset($this->readResources[$id], $this->readHandlers[$id]);
    }

    /**
     * Registers interest in a resource becoming WRITABLE - i.e. its kernel
     * send buffer having room again. Unlike readable interest, this is meant
     * to be short-lived: register while a write buffer has unsent bytes,
     * remove as soon as it drains (see ClientConnection) - a writable socket
     * with nothing to send would otherwise wake the loop constantly, since
     * "writable" is a socket's normal state.
     *
     * @param resource $resource
     */
    public function addWritable(mixed $resource, callable $onWritable): void
    {
        $id = (int) $resource;

        $this->writeResources[$id] = $resource;
        $this->writeHandlers[$id] = $onWritable;
    }

    /** @param resource $resource */
    public function removeWritable(mixed $resource): void
    {
        $id = (int) $resource;

        unset($this->writeResources[$id], $this->writeHandlers[$id]);
    }

    public function hasReadable(): bool
    {
        return $this->readResources !== [];
    }

    /**
     * Blocks until at least one registered resource becomes ready, then
     * invokes the handler registered for each one that did. Returns
     * immediately without blocking if nothing is currently registered —
     * callers rely on that to know there's nothing left worth waiting for.
     *
     * $timeoutSeconds bounds how long that block can last — null (the
     * default) waits indefinitely. A caller that also needs to notice
     * something on a schedule (e.g. Master checking for expired requests)
     * passes a timeout so it regains control periodically even with no
     * socket activity at all.
     *
     * A resource whose peer disconnected also counts as "readable" here
     * (reading it just returns EOF instead of data), so a dead peer wakes
     * this up rather than blocking forever.
     */
    public function tick(?float $timeoutSeconds = null): void
    {
        $this->forgetClosedResources();

        if ($this->readResources === [] && $this->writeResources === []) {
            return;
        }

        $read = array_values($this->readResources);
        $write = array_values($this->writeResources);
        $except = [];

        // The `@` suppresses the "Interrupted system call" warning
        // stream_select() raises when a signal arrives mid-call - which
        // Master relies on, since that is what wakes the loop to check its
        // shutdown flag and drain the signal pipe.
        //
        // Three outcomes, and they must be told apart: a count > 0 means
        // that many resources are ready; 0 means the timeout elapsed with
        // nothing ready; false means the call was interrupted and never
        // completed. On the false path PHP leaves $read/$write UNCHANGED -
        // still holding every registered resource, not the ready ones - so
        // handing that array to the loop below would invoke every handler
        // for an event that never happened. Return instead: nothing is
        // ready, the caller ticks again, and the loop keeps one contract -
        // a handler runs only for a resource stream_select reported ready.
        if ($timeoutSeconds === null) {
            $ready = @stream_select($read, $write, $except, null);
        } else {
            $seconds = (int) $timeoutSeconds;
            $microseconds = (int) (($timeoutSeconds - $seconds) * 1_000_000);

            $ready = @stream_select($read, $write, $except, $seconds, $microseconds);
        }

        if ($ready === false || $ready === 0) {
            return;
        }

        // stream_select() rewrote $read/$write in place to keep only the
        // resources that are actually ready.
        foreach ($read as $resource) {
            $id = (int) $resource;

            if (isset($this->readHandlers[$id])) {
                ($this->readHandlers[$id])();
            }
        }

        foreach ($write as $resource) {
            $id = (int) $resource;

            if (isset($this->writeHandlers[$id])) {
                ($this->writeHandlers[$id])();
            }
        }
    }
}
