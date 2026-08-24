<?php

declare(strict_types=1);

namespace Niladam\LaravelTracing;

use Illuminate\Support\Facades\Context;

/**
 * A W3C Trace Context span.
 *
 * Identifies one unit of work — an HTTP request, a queued job run, a console
 * command — within a single distributed trace. {@see self::$traceId} is minted
 * once at the origin and never regenerated as the trace crosses process
 * boundaries, so it links every log line the originating operation produced,
 * directly or through the jobs it dispatched.
 *
 * @see https://www.w3.org/TR/trace-context/#traceparent-header
 */
final readonly class TraceContext
{
    public const SAMPLED_FLAGS = '01';

    private const VERSION = '00';

    private const INVALID_VERSION = 'ff';

    private const TRACEPARENT_PATTERN = '/^[0-9a-f]{2}-[0-9a-f]{32}-[0-9a-f]{16}-[0-9a-f]{2}$/';

    public function __construct(
        public string $traceId,
        public string $spanId,
        public ?string $parentSpanId,
        public string $traceFlags,
        public ?string $traceState,
    ) {}

    public static function start(): self
    {
        return new self(
            traceId: bin2hex(random_bytes(16)),
            spanId: bin2hex(random_bytes(8)),
            parentSpanId: null,
            traceFlags: self::SAMPLED_FLAGS,
            traceState: null,
        );
    }

    /**
     * Rebuild the sending side's span from a `traceparent` header.
     *
     * Returns null when the header is absent or does not satisfy the spec, so
     * the caller mints a fresh root instead of continuing an unusable trace.
     */
    public static function parse(?string $traceparent, ?string $traceState = null): ?self
    {
        if ($traceparent === null || preg_match(self::TRACEPARENT_PATTERN, $traceparent) !== 1) {
            return null;
        }

        [$version, $traceId, $spanId, $traceFlags] = explode('-', $traceparent);

        if ($version === self::INVALID_VERSION) {
            return null;
        }

        if (trim($traceId, '0') === '' || trim($spanId, '0') === '') {
            return null;
        }

        return new self(
            traceId: $traceId,
            spanId: $spanId,
            parentSpanId: null,
            traceFlags: $traceFlags,
            traceState: $traceState,
        );
    }

    /**
     * Read back the span the current process is running in.
     */
    public static function fromContext(): ?self
    {
        $keys = ContextKeys::resolve();

        if (! Context::has($keys->traceId)) {
            return null;
        }

        return new self(
            traceId: Context::get($keys->traceId),
            spanId: Context::get($keys->spanId),
            parentSpanId: Context::get($keys->parentSpanId),
            traceFlags: Context::getHidden($keys->traceFlags, self::SAMPLED_FLAGS),
            traceState: Context::getHidden($keys->traceState),
        );
    }

    /**
     * Resolve the span an inbound request should run in.
     *
     * A usable `traceparent` joins the caller's trace; otherwise the span this
     * process already opened stands, so a request and the boot that preceded it
     * share one span rather than splitting into two.
     */
    public static function continueFrom(?string $traceparent, ?string $traceState = null): self
    {
        return self::parse($traceparent, $traceState)?->child()
            ?? self::fromContext()
            ?? self::start();
    }

    /**
     * Open a new span in the same trace, parented to this one.
     */
    public function child(): self
    {
        return new self(
            traceId: $this->traceId,
            spanId: bin2hex(random_bytes(8)),
            parentSpanId: $this->spanId,
            traceFlags: $this->traceFlags,
            traceState: $this->traceState,
        );
    }

    public function toTraceparent(): string
    {
        return implode('-', [self::VERSION, $this->traceId, $this->spanId, $this->traceFlags]);
    }

    /**
     * Make this the ambient span: logged through the framework's context log
     * processor, and carried into queued jobs by the context dehydrate/hydrate.
     */
    public function putInContext(): void
    {
        $keys = ContextKeys::resolve();

        Context::add([
            $keys->traceId => $this->traceId,
            $keys->spanId => $this->spanId,
            $keys->parentSpanId => $this->parentSpanId,
        ]);

        Context::addHidden([
            $keys->traceFlags => $this->traceFlags,
            $keys->traceState => $this->traceState,
        ]);

        Context::add((array) config('tracing.additional_context', []));
    }
}
