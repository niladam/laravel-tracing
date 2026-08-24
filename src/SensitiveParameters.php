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
 *
 * Applications with an attribute of their own can list it in
 * `tracing.context.jobs.sensitive_attributes` and have it honoured the same way.
 */
class SensitiveParameters
{
    /** @var array<class-string, list<string>> */
    private array $cache = [];

    /**
     * @param  list<class-string>  $attributes  Attributes that mark a parameter as sensitive.
     */
    public function __construct(private readonly array $attributes = [SensitiveParameter::class]) {}

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
        if ($this->attributes === [] || ! class_exists($class)) {
            return [];
        }

        try {
            $constructor = (new ReflectionClass($class))->getConstructor();
        } catch (Throwable) {
            return [];
        }

        $parameters = array_filter(
            $constructor?->getParameters() ?? [],
            fn (ReflectionParameter $parameter) => $this->isMarked($parameter),
        );

        return array_values(array_map(
            fn (ReflectionParameter $parameter) => $parameter->getName(),
            $parameters,
        ));
    }

    protected function isMarked(ReflectionParameter $parameter): bool
    {
        foreach ($parameter->getAttributes() as $attribute) {
            if (in_array($attribute->getName(), $this->attributes, true)) {
                return true;
            }
        }

        return false;
    }
}
