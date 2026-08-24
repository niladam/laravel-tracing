<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Niladam\LaravelTracing\Facades\Tracing;
use Niladam\LaravelTracing\Http\Middleware\TraceRequests;
use Niladam\LaravelTracing\TraceContext;

beforeEach(function () {
    Route::middleware(TraceRequests::class)->get('/probe', fn () => Context::all());
});

test('the middleware is prepended to the configured groups', function (string $group) {
    expect(app('router')->getMiddlewareGroups()[$group])->toContain(TraceRequests::class);
})->with(['web', 'api']);

test('it is prepended, not appended, so nothing logs ahead of it', function () {
    expect(app('router')->getMiddlewareGroups()['web'][0])->toBe(TraceRequests::class);
});

test('an alias is registered so single routes can opt in', function () {
    expect(app('router')->getMiddleware())->toHaveKey('trace')
        ->and(app('router')->getMiddleware()['trace'])->toBe(TraceRequests::class);
});

test('a route using only the alias is traced', function () {
    Route::middleware('trace')->get('/aliased', fn () => Context::get('trace_id'));

    $this->get('/aliased', ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'])
        ->assertOk()
        ->assertSee('4bf92f3577b34da6a3ce929d0e0e4736');
});

test('a request adopts an inbound traceparent as its parent', function () {
    $this->get('/probe', [
        'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
        'tracestate' => 'vendor=value',
    ])
        ->assertOk()
        ->assertJsonPath('trace_id', '4bf92f3577b34da6a3ce929d0e0e4736')
        ->assertJsonPath('parent_span_id', '00f067aa0ba902b7');
});

test('a malformed traceparent is ignored rather than trusted', function () {
    $this->get('/probe', ['traceparent' => 'nonsense'])
        ->assertOk()
        ->assertJsonPath('parent_span_id', null);
});

test('wire details travel hidden so they stay out of the logs', function () {
    $this->get('/probe', [
        'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
        'tracestate' => 'vendor=value',
    ])->assertOk()->assertJsonMissingPath('trace_state');

    expect(Context::getHidden('trace_state'))->toBe('vendor=value');
});

test('rehydrating a dispatched context opens a child span', function () {
    TraceContext::parse('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01')->child()->putInContext();

    $dispatched = Context::dehydrate();
    $dispatcherSpanId = Context::get('span_id');

    Context::hydrate($dispatched);

    expect(Context::get('trace_id'))->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and(Context::get('parent_span_id'))->toBe($dispatcherSpanId)
        ->and(Context::get('span_id'))->not->toBe($dispatcherSpanId);
});

test('successive jobs in one worker never share a span', function () {
    TraceContext::start()->putInContext();
    $dispatched = Context::dehydrate();

    Context::hydrate($dispatched);
    $first = Context::get('span_id');
    Context::hydrate($dispatched);

    expect(Context::get('span_id'))->not->toBe($first);
});

test('a job dispatched without any context starts its own trace', function () {
    Context::hydrate(null);

    expect(Context::get('trace_id'))->toMatch('/^[0-9a-f]{32}$/')
        ->and(Context::get('parent_span_id'))->toBeNull();
});

test('log records carry the trace merged into a single context blob', function () {
    TraceContext::parse('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01')->child()->putInContext();

    Log::channel('probe')->info('hello', ['own' => 'value']);

    $record = probeRecords()[0];

    expect($record->context['trace_id'])->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and($record->context['own'])->toBe('value')
        ->and($record->extra)->toBe([]);
});

test('sensitive context is masked before it reaches a log line', function () {
    Context::add(['company_id' => 8, 'body.password' => 'hunter2']);

    Log::channel('probe')->info('hello');

    expect(probeRecords()[0]->context)
        ->toMatchArray(['company_id' => 8, 'body.password' => '[redacted]']);
});

test('hidden context travels to jobs but never reaches a log line', function () {
    Context::addHidden('idempotency_key', 'abc-123');

    Log::channel('probe')->info('hello');

    expect(probeRecords()[0]->context)->not->toHaveKey('idempotency_key')
        ->and(Context::dehydrate()['hidden'])->toHaveKey('idempotency_key');
});

test('never_queue keys are stripped from job payloads but kept in the process', function () {
    Context::add(['company_id' => 8, 'body.iban' => 'RO49AAAA1B31007593840000']);

    $dehydrated = Context::dehydrate();

    expect($dehydrated['data'])->toHaveKey('company_id')
        ->and($dehydrated['data'])->not->toHaveKey('body.iban')
        ->and(Context::get('body.iban'))->toBe('RO49AAAA1B31007593840000');
});

test('the facade exposes the current trace', function () {
    $span = TraceContext::parse('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01')->child();
    $span->putInContext();

    expect(Tracing::traceId())->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and(Tracing::spanId())->toBe($span->spanId)
        ->and(Tracing::parentSpanId())->toBe('00f067aa0ba902b7')
        ->and(Tracing::traceparent())->toBe($span->toTraceparent());
});

test('outgoing requests to our own hosts propagate the current span', function () {
    Http::fake();

    TraceContext::parse('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01', 'vendor=value')
        ->child()
        ->putInContext();

    Http::get('https://api.example.test/ping');

    Http::assertSent(fn ($request) => $request->hasHeader('traceparent', Tracing::traceparent())
        && $request->hasHeader('tracestate', 'vendor=value'));
});

test('outgoing requests to third parties leak nothing', function (string $url) {
    Http::fake();

    TraceContext::start()->putInContext();

    Http::get($url);

    Http::assertSent(fn ($request) => ! $request->hasHeader('traceparent'));
})->with([
    'unrelated' => ['https://api.stripe.com/v1/ping'],
    'look-alike' => ['https://evilexample.test/ping'],
    'ours as their subdomain' => ['https://example.test.evil.com/ping'],
    'bucket named after us' => ['https://example.s3.amazonaws.com/ping'],
]);

test('context added with Context directly is traced and logged identically', function () {
    TraceContext::start()->putInContext();

    Context::add('added_natively', 'yes');

    Log::channel('probe')->info('hello');

    expect(probeRecords()[0]->context)->toHaveKey('added_natively')
        ->and(Context::dehydrate()['data'])->toHaveKey('added_natively');
});
