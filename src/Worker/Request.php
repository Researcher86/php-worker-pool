<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * The conventional request envelope the SDK sends (see
 * WorkerPoolClient::call(): `['action' => ..., 'params' => [...]]`), as a
 * DTO an application handler can declare instead of digging through the raw
 * payload array:
 *
 *     $handler = static function (Request $request) {
 *         return match ($request->action) {
 *             'calculate' => calculate(HandlerAdapter::hydrate(CalculateRequest::class, $request->params)),
 *             ...
 *         };
 *     };
 *
 * The envelope is deliberately loose (both fields optional, unknown keys
 * ignored by hydration) - which ACTIONS exist, and the shape of each one's
 * params, is the application's contract, expressed per action by hydrating
 * `$request->params` into that action's own DTO.
 */
final readonly class Request
{
    /** @param array<string, mixed> $params */
    public function __construct(
        public string $action = '',
        public array $params = [],
    ) {
    }
}
