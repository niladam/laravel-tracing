<?php

declare(strict_types=1);

namespace Niladam\LaravelTracing\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Niladam\LaravelTracing\ContextKeys;
use Niladam\LaravelTracing\Events\RequestReceived;
use Niladam\LaravelTracing\Recorders\RecordAuthenticatedUser;
use Niladam\LaravelTracing\TraceContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opens the span an inbound request runs in, and optionally hands the trace back.
 *
 * Register this ahead of anything that logs, in every group that serves
 * requests — an entry point without it silently starts a fresh trace.
 */
class TraceRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $this->startSpan($request);

        return $this->withTraceHeaders($next($request));
    }

    protected function startSpan(Request $request): void
    {
        TraceContext::continueFrom(
            traceparent: $request->header('traceparent'),
            traceState: $request->header('tracestate'),
        )->putInContext();

        $this->recordUpstreamRequestId($request);

        event(new RequestReceived($request));

        app(RecordAuthenticatedUser::class)->watch($request);
    }

    /**
     * Tie the trace to whatever the edge already labelled this request with.
     *
     * A proxy or CDN in front of the application often stamps its own id. It is
     * not a trace id, so it cannot become one, but recording it lets a line in
     * their logs be matched to a trace in ours.
     */
    protected function recordUpstreamRequestId(Request $request): void
    {
        foreach ((array) config('tracing.propagation.inbound_request_ids', []) as $header) {
            $value = $request->header($header);

            if (filled($value)) {
                Context::add(ContextKeys::resolve()->upstreamRequestId, $value);

                return;
            }
        }
    }

    /**
     * Hand the trace back to the caller, so an id is visible without reading logs.
     */
    protected function withTraceHeaders(Response $response): Response
    {
        $span = TraceContext::fromContext();

        if ($span === null) {
            return $response;
        }

        foreach ((array) config('tracing.propagation.response_headers', []) as $header => $part) {
            $value = match ($part) {
                'traceparent' => $span->toTraceparent(),
                'trace_id' => $span->traceId,
                'span_id' => $span->spanId,
                default => null,
            };

            if ($value !== null) {
                $response->headers->set($header, $value);
            }
        }

        return $response;
    }
}
