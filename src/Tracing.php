<?php

declare(strict_types=1);

namespace Niladam\LaravelTracing;

use Illuminate\Support\Facades\Context;

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
