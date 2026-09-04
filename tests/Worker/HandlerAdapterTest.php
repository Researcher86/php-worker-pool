<?php

declare(strict_types=1);

namespace App\Tests\Worker;

use App\Worker\HandlerAdapter;
use App\Worker\PayloadHydrationException;
use PHPUnit\Framework\TestCase;

final class HandlerAdapterTest extends TestCase
{
    public function testArrayTypedHandlerGetsTheRawPayload(): void
    {
        $adapted = HandlerAdapter::adapt(static fn (array $payload): array => ['seen' => $payload]);

        $this->assertSame(
            ['seen' => ['x' => 1, 'extra' => 'kept']],
            $adapted(['x' => 1, 'extra' => 'kept'])
        );
    }

    public function testUntypedHandlerGetsTheRawPayloadToo(): void
    {
        $adapted = HandlerAdapter::adapt(static fn ($payload): array => ['seen' => $payload]);

        $this->assertSame(['seen' => ['x' => 1]], $adapted(['x' => 1]));
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
            $adapted(['id' => 7, 'extra' => 'ignored'])
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

        $this->assertSame(['who' => 'alice', 'order' => 42], $result);
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

        $this->assertSame(['id' => 3, 'note' => 'shipped'], $adapted([]));
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
