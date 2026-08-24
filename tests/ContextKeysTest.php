<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Niladam\LaravelTracing\ContextKeys;
use Niladam\LaravelTracing\Facades\Tracing;
use Niladam\LaravelTracing\Http\Middleware\TraceRequests;
use Niladam\LaravelTracing\TraceContext;

test('keys default to the plain names', function () {
    expect(ContextKeys::resolve()->traceId)->toBe('trace_id')
        ->and(ContextKeys::resolve()->parentSpanId)->toBe('parent_span_id');
});

test('renamed keys are what the trace is stored and logged under', function () {
    app()->instance(ContextKeys::class, ContextKeys::fromArray([
        'trace_id' => 'dd.trace_id',
        'span_id' => 'dd.span_id',
    ]));

    Context::flush();

    Route::middleware(TraceRequests::class)->get('/probe', fn () => 'ok');

    $this->get('/probe', [
        'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
    ])->assertOk();

    Log::channel('probe')->info('hello');

    expect(Context::get('dd.trace_id'))->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and(Context::has('trace_id'))->toBeFalse()
        ->and(probeRecords()[0]->context)->toHaveKey('dd.trace_id');
});

test('a renamed key is still readable through the facade', function () {
    app()->instance(ContextKeys::class, ContextKeys::fromArray(['trace_id' => 'dd.trace_id']));

    Context::flush();

    TraceContext::parse('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01')->putInContext();

    expect(Tracing::traceId())->toBe('4bf92f3577b34da6a3ce929d0e0e4736');
});

test('partial renames keep the defaults for the rest', function () {
    $keys = ContextKeys::fromArray(['trace_id' => 'dd.trace_id']);

    expect($keys->traceId)->toBe('dd.trace_id')
        ->and($keys->spanId)->toBe('span_id');
});
