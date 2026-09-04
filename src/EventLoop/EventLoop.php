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
        // stream_select() raises if a signal arrives mid-call (Master
        // relies on exactly that to wake up and check its shutdown flag on
        // SIGINT/SIGTERM). On that path $read/$write are left unchanged —
        // still every resource, not just the ready ones — since the call
        // never completed; that's harmless here, each handler independently
        // no-ops when there's nothing actually waiting for it (a write
        // handler's flush attempt just writes 0 bytes). A genuine timeout
        // (no activity, no signal), by contrast, correctly leaves both empty.
        if ($timeoutSeconds === null) {
            @stream_select($read, $write, $except, null);
        } else {
            $seconds = (int) $timeoutSeconds;
            $microseconds = (int) (($timeoutSeconds - $seconds) * 1_000_000);

            @stream_select($read, $write, $except, $seconds, $microseconds);
        }

        // stream_select() rewrites $read/$write in place to keep only the
        // resources that are actually ready, so this only invokes handlers
        // for those (or, on an interrupted call, every resource — see above).
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
