<?php

declare(strict_types=1);

namespace Niladam\LaravelTracing;

use Closure;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;

/**
 * Reads the trace the current request, job or command is running in.
 *
 * Your own data goes in Laravel's {@see Context},
 * which this package traces, logs and carries into jobs as-is — there is no
 * second store and no wrapper to learn. What lives here is only what Context
 * cannot answer on its own: where the ids are, whatever you have named them.
 */
class Tracing
{
    /** @var array<string, list<callable>> Guard name (or '*') to user recorders. */
    private array $userRecorders = [];

    /**
     * Merge context whenever an event fires.
     *
     * The recorder returns an array of keys, and may be a closure or the name
     * of an invokable class, so a growing list can move out of the closure and
     * into its own testable class without changing the call site.
     *
     * ```php
     * Tracing::on(OrderShipped::class, fn ($event) => ['order_id' => $event->order->id]);
     * Tracing::on(OrderShipped::class, ShipmentContext::class);
     * ```
     */
    public function on(string $event, Closure|string $recorder): static
    {
        Event::listen($event, function (...$payload) use ($recorder): void {
            $keys = $this->resolve($recorder)(...$payload);

            if (is_array($keys) && $keys !== []) {
                Context::add($keys);
            }
        });

        return $this;
    }

    /**
     * Merge context the moment a guard answers with a user.
     *
     * Works for session and stateless guards alike; pass '*' for any guard.
     *
     * ```php
     * Tracing::authenticated('web', fn (User $user) => ['company_id' => $user->current_company_id]);
     * ```
     */
    public function authenticated(string $guard, Closure|string $recorder): static
    {
        $this->userRecorders[$guard][] = $this->resolve($recorder);

        return $this;
    }

    /**
     * @internal
     *
     * @return Collection<int, callable>
     */
    public function recordersFor(?string $guard): Collection
    {
        return new Collection([
            ...$this->userRecorders['*'] ?? [],
            ...$this->userRecorders[$guard] ?? [],
        ]);
    }

    protected function resolve(Closure|string $recorder): callable
    {
        return $recorder instanceof Closure ? $recorder : app($recorder)(...);
    }

    public function trace(): ?TraceContext
    {
        return TraceContext::fromContext();
    }

    public function traceId(): ?string
    {
        return $this->trace()?->traceId;
    }

    public function spanId(): ?string
    {
        return $this->trace()?->spanId;
    }

    public function parentSpanId(): ?string
    {
        return $this->trace()?->parentSpanId;
    }

    /**
     * The `traceparent` header value for the current span, to hand to a client
     * this package does not propagate for.
     */
    public function traceparent(): ?string
    {
        return $this->trace()?->toTraceparent();
    }

    /**
     * Abandon the current trace and begin a new one.
     *
     * For a long-running process handling unrelated units of work, so they do
     * not all share the trace it booted with.
     */
    public function startNewTrace(): TraceContext
    {
        return tap(TraceContext::start(), fn (TraceContext $span) => $span->putInContext());
    }
}
