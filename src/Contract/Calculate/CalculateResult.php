<?php

declare(strict_types=1);

namespace App\Contract\Calculate;

/**
 * What the `calculate` action produces. Response::of() turns its public
 * state into the response payload, so the action never touches the wire
 * format itself.
 */
final readonly class CalculateResult
{
    public function __construct(
        public int|float $result,
    ) {
    }
}
