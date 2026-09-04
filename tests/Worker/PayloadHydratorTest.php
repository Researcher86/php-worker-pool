<?php

declare(strict_types=1);

namespace App\Tests\Worker;

use App\Worker\PayloadHydrationException;
use App\Worker\PayloadHydrator;
use App\Worker\Request;
use PHPUnit\Framework\TestCase;

final class PayloadHydratorTest extends TestCase
{
    public function testHydratesConstructorParametersFromMatchingPayloadKeys(): void
    {
        $order = PayloadHydrator::hydrate(OrderDto::class, ['id' => 7, 'note' => 'rush']);

        $this->assertSame(7, $order->id);
        $this->assertSame('rush', $order->note);
    }

    public function testAbsentOptionalParameterFallsBackToItsDefaultAndExtraKeysAreIgnored(): void
    {
        $order = PayloadHydrator::hydrate(OrderDto::class, ['id' => 7, 'extra' => 'ignored']);

        $this->assertSame(7, $order->id);
        $this->assertSame('none', $order->note);
    }

    public function testClassTypedParametersAreHydratedRecursivelyFromNestedArrays(): void
    {
        $request = PayloadHydrator::hydrate(RequestDto::class, [
            'user' => ['name' => 'alice'],
            'order' => ['id' => 42],
        ]);

        $this->assertSame('alice', $request->user->name);
        $this->assertSame(42, $request->order->id);
    }

    public function testMissingRequiredKeyThrowsPayloadHydration(): void
    {
        $this->expectException(PayloadHydrationException::class);
        $this->expectExceptionMessage('missing "id"');

        PayloadHydrator::hydrate(OrderDto::class, ['note' => 'id is required but absent']);
    }

    public function testWrongValueTypeThrowsPayloadHydrationNotTypeError(): void
    {
        $this->expectException(PayloadHydrationException::class);

        PayloadHydrator::hydrate(OrderDto::class, ['id' => 'not-an-int']);
    }

    /**
     * The envelope WorkerRunner hydrates every request payload into before
     * calling the handler. Both fields are optional, so an unexpected
     * payload shape still produces a well-formed Request rather than an
     * error - which actions exist is the application's contract, not the
     * envelope's.
     */
    public function testRequestEnvelopeHydratesFromTheWireShapeAndToleratesAnEmptyPayload(): void
    {
        $request = PayloadHydrator::hydrate(Request::class, [
            'action' => 'calculate',
            'params' => ['a' => 1, 'b' => 2],
        ]);

        $this->assertSame('calculate', $request->action);
        $this->assertSame(['a' => 1, 'b' => 2], $request->params);

        $empty = PayloadHydrator::hydrate(Request::class, []);

        $this->assertSame('', $empty->action);
        $this->assertSame([], $empty->params);
    }

    public function testEnvelopeWithAWrongTypedFieldIsRejected(): void
    {
        $this->expectException(PayloadHydrationException::class);

        PayloadHydrator::hydrate(Request::class, ['action' => 123]);
    }

    public function testClassWithoutAConstructorCannotBeHydrated(): void
    {
        $this->expectException(PayloadHydrationException::class);
        $this->expectExceptionMessage('no constructor');

        PayloadHydrator::hydrate(NoConstructorDto::class, []);
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

final class NoConstructorDto
{
}
