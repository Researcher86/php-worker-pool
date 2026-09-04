<?php

declare(strict_types=1);

namespace App\Contract\Calculate;

/**
 * The `calculate` action: an ordinary typed callable, knowing nothing about
 * sockets, framing, workers, or the envelope types around it - bin/server.php
 * is what routes to it and hands it a hydrated CalculateRequest.
 *
 * Invokable rather than a plain function so it can hold collaborators
 * (a repository, an HTTP client, ...) in its constructor once an action
 * needs any - and so the whole application layer lives in App\Contract,
 * autoloaded like everything else, instead of inside a bin/ script.
 */
final readonly class CalculateAction
{
    public function __invoke(CalculateRequest $request): CalculateResult
    {
        return new CalculateResult($request->a + $request->b);
    }
}
