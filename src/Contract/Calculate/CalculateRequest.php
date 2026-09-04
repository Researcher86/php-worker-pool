<?php

declare(strict_types=1);

namespace App\Contract\Calculate;

/**
 * What the `calculate` action needs, as the worker side defines it:
 * PayloadHydrator builds one of these out of a request's `params`, so a
 * payload missing a key or carrying the wrong type is rejected as
 * invalid_payload before the action ever runs.
 */
final readonly class CalculateRequest
{
    public function __construct(
        public int $a,
        public int $b,
    ) {
    }
}
