<?php

declare(strict_types=1);

namespace App\Metrics;

/**
 * Running count, mean and worst case for one kind of duration.
 *
 * Deliberately not percentiles: those need the samples kept, and a
 * long-running Master would then hold an ever-growing array of floats for
 * the sake of a number nobody reads until something is wrong. Count, mean
 * and max are three scalars, answer the operational question ("is the
 * average bad, or is one request dragging the tail?"), and cost nothing to
 * carry. Reach for a real histogram when this proves too coarse - the shape
 * of that change is a different implementation of this class, not a change
 * to any caller.
 */
final class DurationStat
{
    private int $count = 0;

    private float $totalSeconds = 0.0;

    private float $maxSeconds = 0.0;

    public function record(float $seconds): void
    {
        $this->count++;
        $this->totalSeconds += $seconds;
        $this->maxSeconds = max($this->maxSeconds, $seconds);
    }

    public function summary(): DurationSummary
    {
        return new DurationSummary(
            count: $this->count,
            averageMs: $this->count === 0 ? 0.0 : ($this->totalSeconds / $this->count) * 1000,
            maxMs: $this->maxSeconds * 1000,
        );
    }
}
