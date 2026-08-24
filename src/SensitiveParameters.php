<?php

declare(strict_types=1);

namespace Niladam\LaravelTracing;

use ReflectionClass;
use ReflectionParameter;
use SensitiveParameter;
use Throwable;

/**
 * Finds the constructor parameters a class has marked as sensitive.
 *
 * PHP's own {@see SensitiveParameter} already keeps a value out of stack
 * traces, so a class that has declared its secrets should not have to declare
 * them a second time to keep them out of a trace.
 *
 * ```php
 * public function __construct(
 *     public string $invoiceId,
 *     #[\SensitiveParameter] public string $cardToken,
 * ) {}
 * ```
 */
class SensitiveParameters
{
    /** @var array<class-string, list<string>> */
    private array $cache = [];

    /**
     * @return list<string>
     */
    public function for(string $class): array
    {
        return $this->cache[$class] ??= $this->discover($class);
    }

    /**
     * @return list<string>
     */
    protected function discover(string $class): array
    {
        if (! class_exists($class)) {
            return [];
        }

        try {
            $constructor = (new ReflectionClass($class))->getConstructor();
        } catch (Throwable) {
            return [];
        }

        $parameters = array_filter(
            $constructor?->getParameters() ?? [],
            fn (ReflectionParameter $parameter) => $parameter->getAttributes(SensitiveParameter::class) !== [],
        );

        return array_values(array_map(
            fn (ReflectionParameter $parameter) => $parameter->getName(),
            $parameters,
        ));
    }
}
