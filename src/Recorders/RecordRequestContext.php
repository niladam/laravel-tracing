<?php

declare(strict_types=1);

namespace Niladam\LaravelTracing\Recorders;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Niladam\LaravelTracing\Channel;
use Niladam\LaravelTracing\Contracts\Recorder;
use Niladam\LaravelTracing\Events\RequestReceived;

/**
 * Records what the request was.
 *
 * The payload is kept separate and off by default: a request body can be large
 * enough to bloat every line the request writes, and is the most likely place
 * for something you would rather not keep. Whatever is recorded still passes
 * through redaction on its way to a log line.
 */
class RecordRequestContext implements Recorder
{
    public static function listensTo(): string
    {
        return RequestReceived::class;
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(object $event): array
    {
        $request = $event->request;

        return [
            'channel' => Channel::Http,
            'ip' => $request->ip(),
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            ...$this->payload($request),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(Request $request): array
    {
        if (! config('tracing.request_payload', false)) {
            return [];
        }

        return Arr::dot([
            'body' => $request->post(),
            'query' => $request->query(),
        ]);
    }
}
