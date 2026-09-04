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
 * stays open. Each resources map is kept in lockstep with its handlers map
 * by that same id.
 */
final class EventLoop
{
    /** @var array<int, resource> */
    private array $resources = [];

    /** @var array<int, callable(): void> */
    private array $handlers = [];

    /** @var array<int, resource> */
    private array $writeResources = [];

    /** @var array<int, callable(): void> */
    private array $writeHandlers = [];

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
        return $this->resources !== [];
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
        if ($this->resources === [] && $this->writeResources === []) {
            return;
        }

        $read = array_values($this->resources);
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

            if (isset($this->handlers[$id])) {
                ($this->handlers[$id])();
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
