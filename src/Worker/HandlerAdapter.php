<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * Adapts an application handler of whatever signature it prefers to the
 * runtime's canonical `array payload in -> array payload out` contract
 * (see WorkerRunner), driven by the handler's own declared types:
 *
 *  - `function (array $payload)` (or untyped/mixed): the raw payload array
 *    is passed through as-is.
 *  - `function (CalculateRequest $request)`: the payload array is hydrated
 *    into that DTO via its constructor - payload keys matched to parameter
 *    names, absent optional parameters falling back to their defaults,
 *    class-typed parameters hydrated recursively from nested arrays. A
 *    payload that doesn't fit throws PayloadHydrationException (answered as
 *    `invalid_payload` - the client's fault, not the handler's).
 *  - The return value may be an array (used as-is) or an object (its
 *    JSON-visible state - public properties, or JsonSerializable - becomes
 *    the response payload). Anything else is a handler bug and throws.
 *
 * The signature is reflected ONCE, when the worker starts - per-request work
 * is only the hydration itself, and none at all for array-typed handlers.
 */
final class HandlerAdapter
{
    /**
     * @return \Closure(array<string, mixed>): array<string, mixed>
     */
    public static function adapt(\Closure $handler): \Closure
    {
        $payloadClass = self::payloadClass($handler);

        return static function (array $payload) use ($handler, $payloadClass): array {
            $argument = $payloadClass === null ? $payload : self::hydrate($payloadClass, $payload);

            return self::normalize($handler($argument));
        };
    }

    /**
     * The DTO class the handler's first parameter declares, or null for
     * "hand over the raw array" (array/mixed/untyped/union - anything that
     * isn't exactly one class type).
     *
     * @return class-string|null
     */
    private static function payloadClass(\Closure $handler): ?string
    {
        $parameters = (new \ReflectionFunction($handler))->getParameters();

        if ($parameters === []) {
            return null;
        }

        $type = $parameters[0]->getType();

        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        /** @var class-string */
        return $type->getName();
    }

    /**
     * Builds the DTO from the payload via its constructor: payload keys are
     * matched to parameter names (extra keys are ignored), a parameter with
     * a default may be absent, a class-typed parameter whose value is an
     * array is hydrated recursively.
     *
     * Public on purpose: besides backing the signature-driven hydration
     * above, an application handler that routes per action (see Request) can
     * call it directly to hydrate `$request->params` into the matched
     * action's own DTO. A PayloadHydrationException thrown from there is
     * still answered as invalid_payload - WorkerRunner classifies by
     * exception type, not by where it was thrown.
     *
     * @template T of object
     *
     * @param class-string<T>      $class
     * @param array<string, mixed> $payload
     *
     * @return T
     *
     * @throws PayloadHydrationException
     */
    public static function hydrate(string $class, array $payload): object
    {
        $constructor = (new \ReflectionClass($class))->getConstructor();

        if ($constructor === null) {
            throw new PayloadHydrationException(sprintf('%s has no constructor to hydrate through', $class));
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();

            if (!array_key_exists($name, $payload)) {
                if ($parameter->isDefaultValueAvailable()) {
                    continue; // named-argument call: skipping it applies the default
                }

                throw new PayloadHydrationException(sprintf('Payload is missing "%s", required by %s', $name, $class));
            }

            $value = $payload[$name];
            $type = $parameter->getType();

            // A nested DTO: class-typed parameter fed by a nested array.
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && is_array($value)) {
                /** @var class-string $nested */
                $nested = $type->getName();
                $value = self::hydrate($nested, $value);
            }

            $arguments[$name] = $value;
        }

        try {
            return new $class(...$arguments);
        } catch (\TypeError $e) {
            // A payload value of the wrong type for a typed parameter - the
            // client's fault, same as a missing key.
            throw new PayloadHydrationException(sprintf('Payload does not fit %s: %s', $class, $e->getMessage()), 0, $e);
        }
    }

    /**
     * The response payload for whatever the handler returned: an array
     * as-is; an object through its JSON-visible state (the same view the
     * wire encoding would take anyway). Anything else is a handler bug -
     * thrown as a plain RuntimeException so WorkerRunner reports it as
     * handler_failed, not invalid_payload.
     *
     * @return array<string, mixed>
     */
    private static function normalize(mixed $result): array
    {
        if (is_array($result)) {
            return $result;
        }

        if (is_object($result)) {
            $decoded = json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($decoded)) {
                throw new \RuntimeException(sprintf('%s does not expose any JSON-visible state to respond with', $result::class));
            }

            return $decoded;
        }

        throw new \RuntimeException(sprintf('Handler must return an array or an object, got %s', get_debug_type($result)));
    }
}
