<?php

declare(strict_types=1);

namespace Niladam\LaravelTracing\Facades;

use Illuminate\Support\Facades\Facade;
use Niladam\LaravelTracing\TraceContext;

/**
 * @method static \Niladam\LaravelTracing\Tracing always(\Closure|string $recorder)
 * @method static \Niladam\LaravelTracing\Tracing on(string $event, \Closure|string $recorder)
 * @method static \Niladam\LaravelTracing\Tracing authenticated(string $guard, \Closure|string $recorder)
 * @method static list<string> sensitiveParametersFor(string $class)
 * @method static TraceContext|null trace()
 * @method static string|null traceId()
 * @method static string|null spanId()
 * @method static string|null parentSpanId()
 * @method static string|null traceparent()
 * @method static TraceContext startNewTrace()
 *
 * @see \Niladam\LaravelTracing\Tracing
 */
class Tracing extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Niladam\LaravelTracing\Tracing::class;
    }
}
