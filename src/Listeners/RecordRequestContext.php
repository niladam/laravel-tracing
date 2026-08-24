<?php

declare(strict_types=1);

namespace Niladam\LaravelTracing\Listeners;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Context;
use Niladam\LaravelTracing\Channel;

/**
 * Records what the request was.
 *
 * The payload is kept separate and off by default: a request body can be large
 * enough to bloat every line the request writes, and is the most likely place
 * for something you would rather not keep. Whatever is recorded still passes
 * through redaction on its way to a log line.
 */
class RecordRequestContext
{
    public function handle(Request $request): void
    {
        Context::add([
            'channel' => Channel::Http->value,
            'ip' => $request->ip(),
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            ...$this->payload($request),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(Request $request): array
    {
        if (! config('tracing.record.request_payload', false)) {
            return [];
        }

        return Arr::dot([
            'body' => $request->post(),
            'query' => $request->query(),
        ]);
    }
}
