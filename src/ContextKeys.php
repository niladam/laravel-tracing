<?php

declare(strict_types=1);

namespace Niladam\LaravelTracing;

/**
 * The context keys the trace is stored under.
 *
 * Renaming these lets the trace drop straight into a log pipeline that already
 * expects particular names — Datadog reads `dd.trace_id`, for instance — rather
 * than asking that pipeline to change.
 */
class ContextKeys
{
    public function __construct(
        public readonly string $traceId = 'trace_id',
        public readonly string $spanId = 'span_id',
        public readonly string $parentSpanId = 'parent_span_id',
        public readonly string $traceFlags = 'trace_flags',
        public readonly string $traceState = 'trace_state',
        public readonly string $upstreamRequestId = 'upstream_request_id',
    ) {}

    /**
     * @param  array<string, string>  $keys
     */
    public static function fromArray(array $keys): self
    {
        $defaults = new self;

        return new self(
            traceId: $keys['trace_id'] ?? $defaults->traceId,
            spanId: $keys['span_id'] ?? $defaults->spanId,
            parentSpanId: $keys['parent_span_id'] ?? $defaults->parentSpanId,
            traceFlags: $keys['trace_flags'] ?? $defaults->traceFlags,
            traceState: $keys['trace_state'] ?? $defaults->traceState,
            upstreamRequestId: $keys['upstream_request_id'] ?? $defaults->upstreamRequestId,
        );
    }

    public static function resolve(): self
    {
        return app(self::class);
    }
}
