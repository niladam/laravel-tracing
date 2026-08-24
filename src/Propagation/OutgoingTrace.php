<?php

declare(strict_types=1);

namespace Niladam\LaravelTracing\Propagation;

use Illuminate\Support\Str;
use Niladam\LaravelTracing\TraceContext;

/**
 * Decides what a given host may be told about the current trace.
 *
 * Every client asks this the same question, so the first-party rule cannot
 * drift between them.
 */
class OutgoingTrace
{
    /**
     * @param  list<string>  $domains  Bare domains whose hosts and subdomains may be traced.
     */
    public function __construct(private readonly array $domains) {}

    /**
     * Trace headers for a host, or an empty array when it is not ours to trace.
     *
     * @return array<string, string>
     */
    public function headersFor(string $host): array
    {
        if (! $this->isFirstPartyHost($host)) {
            return [];
        }

        $span = TraceContext::fromContext();

        if ($span === null) {
            return [];
        }

        return array_filter([
            'traceparent' => $span->toTraceparent(),
            'tracestate' => $span->traceState,
        ], fn (?string $value) => $value !== null);
    }

    /**
     * @return array<string, string>
     */
    public function headersForUrl(string $url): array
    {
        return $this->headersFor((string) parse_url($url, PHP_URL_HOST));
    }

    /**
     * Whether a host is one of ours, so a trace never reaches a third party.
     *
     * Matched on a label boundary rather than as a substring, so a look-alike
     * domain or a bucket named after us is still treated as somebody else's.
     */
    public function isFirstPartyHost(string $host): bool
    {
        foreach ($this->domains as $domain) {
            if ($domain !== '' && Str::is([$domain, "*.{$domain}"], $host)) {
                return true;
            }
        }

        return false;
    }
}
