<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal logging seam for events that are deliberately survived rather than
 * propagated (e.g. WorkerPool's recovered launch failures) - without it,
 * those events vanish without a trace: they're not exceptions anymore and no
 * metric counts them. Deliberately one method, not PSR-3: this codebase has
 * no dependencies and only needs "record that this happened", not levels,
 * channels, or placeholders. Swap in PSR-3 behind this interface if a real
 * application ever needs more.
 */
interface Logger
{
    public function log(string $message): void;
}
