<?php

declare(strict_types=1);

namespace App\EventLoop;

/**
 * Minimal reactor: components register a readable resource plus a callback,
 * and the loop multiplexes a single stream_select() across every registered
 * resource, invoking the matching callback for each one that became readable.
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
     * immediately without blocking if nothing is currently registered.
     */
    public function tick(): void
    {
        if ($this->resources === []) {
            return;
        }

        $read = array_values($this->resources);
        $write = [];
        $except = [];

        stream_select($read, $write, $except, null);

        foreach ($read as $resource) {
            $id = (int) $resource;

            if (isset($this->handlers[$id])) {
                ($this->handlers[$id])();
            }
        }
    }
}
