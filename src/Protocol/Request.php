<?php

declare(strict_types=1);

namespace App\Protocol;

/**
 * The application-level request envelope both ends agree on, carried inside
 * a Message's payload: which action to run, and the params for it.
 *
 * The same type spans the whole round trip - the SDK sends one:
 *
 *     $client->call(new Request('calculate', new CalculateRequest(a: 10, b: 20)));
 *
 * and the worker's handler receives one:
 *
 *     static function (Request $request): Response {
 *         return match ($request->action) {
 *             'calculate' => Response::of(calculate(PayloadHydrator::hydrate(CalculateRequest::class, $request->params))),
 *             default => Response::error('unknown_action'),
 *         };
 *     }
 *
 * $params accepts a DTO as readily as an array (normalized by Payload) so a
 * call site can stay typed, but is STORED as an array: it crosses a process
 * boundary as JSON, and the receiving side is the one that decides which
 * class those keys mean - the two processes share the wire shape, not a
 * class.
 *
 * Both fields are optional on purpose: hydrating an unexpected payload shape
 * still yields a well-formed Request rather than an error, because which
 * actions exist is the application's contract, not the envelope's.
 */
final readonly class Request
{
    public string $action;

    /** @var array<string, mixed> */
    public array $params;

    /**
     * Neither property is promoted, so that they are DECLARED in this order:
     * json_encode() follows declaration order, and the envelope should read
     * `{"action": ..., "params": ...}` on the wire rather than the reverse.
     *
     * @param array<string, mixed>|object $params
     */
    public function __construct(string $action = '', array|object $params = [])
    {
        $this->action = $action;
        $this->params = Payload::of($params);
    }
}
