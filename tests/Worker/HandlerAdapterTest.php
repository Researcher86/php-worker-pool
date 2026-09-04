<?php

declare(strict_types=1);

namespace App\Tests\Worker;

use App\Worker\HandlerAdapter;
use App\Worker\PayloadHydrationException;
use App\Worker\Request;
use App\Worker\Response;
use PHPUnit\Framework\TestCase;

final class HandlerAdapterTest extends TestCase
{
    public function testArrayTypedHandlerGetsTheRawPayload(): void
    {
        $adapted = HandlerAdapter::adapt(static fn (array $payload): array => ['seen' => $payload]);

        $this->assertSame(
            ['seen' => ['x' => 1, 'extra' => 'kept']],
            $adapted(['x' => 1, 'extra' => 'kept'])->payload
        );
    }

    public function testUntypedHandlerGetsTheRawPayloadToo(): void
    {
        $adapted = HandlerAdapter::adapt(static fn ($payload): array => ['seen' => $payload]);

        $this->assertSame(['seen' => ['x' => 1]], $adapted(['x' => 1])->payload);
    }

    public function testDtoTypedHandlerGetsTheHydratedObject(): void
    {
        $adapted = HandlerAdapter::adapt(static function (OrderDto $order): array {
            return ['id' => $order->id, 'note' => $order->note];
        });

        // 'note' is absent - the constructor default applies; 'extra' is
        // ignored rather than rejected.
        $this->assertSame(
            ['id' => 7, 'note' => 'none'],
            $adapted(['id' => 7, 'extra' => 'ignored'])->payload
        );
    }

    public function testNestedDtoParametersAreHydratedRecursively(): void
    {
        $adapted = HandlerAdapter::adapt(static function (RequestDto $request): array {
            return ['who' => $request->user->name, 'order' => $request->order->id];
        });

        $result = $adapted([
            'user' => ['name' => 'alice'],
            'order' => ['id' => 42],
        ]);

        $this->assertSame(['who' => 'alice', 'order' => 42], $result->payload);
    }

    public function testMissingRequiredKeyThrowsPayloadHydration(): void
    {
        $adapted = HandlerAdapter::adapt(static fn (OrderDto $order): array => []);

        $this->expectException(PayloadHydrationException::class);
        $this->expectExceptionMessage('missing "id"');

        $adapted(['note' => 'id is required but absent']);
    }

    public function testWrongValueTypeThrowsPayloadHydrationNotTypeError(): void
    {
        $adapted = HandlerAdapter::adapt(static fn (OrderDto $order): array => []);

        $this->expectException(PayloadHydrationException::class);

        $adapted(['id' => 'not-an-int']);
    }

    public function testReturnedObjectIsNormalizedToItsPublicState(): void
    {
        $adapted = HandlerAdapter::adapt(static fn (array $payload): OrderDto => new OrderDto(3, 'shipped'));

        $this->assertSame(['id' => 3, 'note' => 'shipped'], $adapted([])->payload);
    }

    /**
     * The routing pattern bin/server.php uses: the handler declares the
     * conventional Request envelope (what WorkerPoolClient::call() sends),
     * matches on its action, and hydrates $request->params into that
     * action's own DTO - each action staying an ordinary typed function.
     */
    public function testRequestEnvelopeRoutesToPerActionDtos(): void
    {
        $adapted = HandlerAdapter::adapt(static function (Request $request): array {
            return match ($request->action) {
                'order' => self::describeOrder(HandlerAdapter::hydrate(OrderDto::class, $request->params)),
                default => [],
            };
        });

        $this->assertSame(
            ['id' => 7, 'note' => 'rush'],
            $adapted(['action' => 'order', 'params' => ['id' => 7, 'note' => 'rush']])->payload
        );

        // Unknown action - the envelope's own defaults keep this well-formed.
        $this->assertSame([], $adapted(['action' => 'nope', 'params' => []])->payload);
    }

    /**
     * A params payload that doesn't fit the ACTION's DTO must be reported the
     * same way as one that doesn't fit the handler's own parameter: the
     * hydration exception thrown from inside the handler still reaches
     * WorkerRunner as PayloadHydrationException, so it's answered as
     * invalid_payload rather than handler_failed.
     */
    public function testPerActionHydrationFailurePropagatesAsPayloadHydration(): void
    {
        $adapted = HandlerAdapter::adapt(static function (Request $request): array {
            return self::describeOrder(HandlerAdapter::hydrate(OrderDto::class, $request->params));
        });

        $this->expectException(PayloadHydrationException::class);

        $adapted(['action' => 'order', 'params' => ['note' => 'id is missing']]);
    }

    /** @return array{id: int, note: string} */
    private static function describeOrder(OrderDto $order): array
    {
        return ['id' => $order->id, 'note' => $order->note];
    }

    /**
     * A Response the handler built is passed through untouched - it's the
     * only return form that can express a deliberate failure, which a bare
     * array or DTO cannot (those are always a success).
     */
    public function testReturnedResponseIsUsedAsIs(): void
    {
        $ok = HandlerAdapter::adapt(static fn (array $payload): Response => new Response(['pong' => true]));

        $this->assertTrue($ok([])->successful);
        $this->assertSame(['pong' => true], $ok([])->payload);

        $failed = HandlerAdapter::adapt(
            static fn (array $payload): Response => Response::error('unknown_action', ['action' => 'nope'])
        );

        $this->assertFalse($failed([])->successful);
        $this->assertSame(['error' => 'unknown_action', 'action' => 'nope'], $failed([])->payload);
    }

    public function testBareArrayOrDtoReturnIsAlwaysASuccessfulResponse(): void
    {
        $fromArray = HandlerAdapter::adapt(static fn (array $payload): array => ['x' => 1]);
        $fromDto = HandlerAdapter::adapt(static fn (array $payload): OrderDto => new OrderDto(1));

        $this->assertTrue($fromArray([])->successful);
        $this->assertTrue($fromDto([])->successful);
    }

    public function testScalarReturnIsAHandlerBugNotAPayloadProblem(): void
    {
        $adapted = HandlerAdapter::adapt(static fn (array $payload): int => 42);

        try {
            $adapted([]);
            $this->fail('a scalar return must throw');
        } catch (\RuntimeException $e) {
            // Plain RuntimeException, NOT PayloadHydrationException: this is
            // the handler's fault (reported as handler_failed), not the
            // client's (invalid_payload).
            $this->assertNotInstanceOf(PayloadHydrationException::class, $e);
        }
    }
}

/** Test DTOs, application-style: promoted readonly constructor properties. */
final readonly class OrderDto
{
    public function __construct(
        public int $id,
        public string $note = 'none',
    ) {
    }
}

final readonly class UserDto
{
    public function __construct(
        public string $name,
    ) {
    }
}

final readonly class RequestDto
{
    public function __construct(
        public UserDto $user,
        public OrderDto $order,
    ) {
    }
}
