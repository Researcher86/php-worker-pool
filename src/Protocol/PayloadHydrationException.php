<?php

declare(strict_types=1);

namespace App\Protocol;

use RuntimeException;

/**
 * The request payload doesn't fit the DTO the application handler declared
 * (missing key, wrong type, ...) - the CLIENT's fault, as opposed to the
 * handler itself throwing (the application's fault). WorkerRunner answers
 * the former with `invalid_payload` and the latter with `handler_failed`,
 * so the two are distinguishable on the wire.
 */
final class PayloadHydrationException extends RuntimeException
{
}
