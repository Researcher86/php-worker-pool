<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * Builds a DTO out of a payload array via the DTO's constructor: payload
 * keys are matched to parameter names (extra keys ignored), a parameter with
 * a default may be absent, and a class-typed parameter fed a nested array is
 * hydrated recursively.
 *
 * Used in two places, both worker-side:
 *  - WorkerRunner hydrates every request payload into the Request envelope
 *    before handing it to the application handler.
 *  - The handler itself hydrates `$request->params` into the matched
 *    action's own DTO (see bin/server.php), so each action stays an ordinary
 *    typed function.
 *
 * Either way a payload that doesn't fit throws PayloadHydrationException,
 * which WorkerRunner answers as `invalid_payload` - classified by exception
 * type, not by where it was thrown, so both cases look the same to a client.
 */
final class PayloadHydrator
{
    /**
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
}
