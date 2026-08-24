<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Niladam\LaravelTracing\Http\Middleware\TraceRequests;

beforeEach(function () {
    Route::middleware(TraceRequests::class)->get('/probe', fn () => 'ok');
});

test('the trace id comes back on the response by default', function () {
    $response = $this->get('/probe')->assertOk();

    expect($response->headers->get('X-Trace-Id'))->toBe(Context::get('trace_id'));
});

test('a response header can carry the full traceparent', function () {
    config()->set('tracing.propagation.response_headers', ['traceparent' => 'traceparent']);

    $response = $this->get('/probe', [
        'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
    ])->assertOk();

    expect($response->headers->get('traceparent'))
        ->toStartWith('00-4bf92f3577b34da6a3ce929d0e0e4736-')
        ->not->toContain('00f067aa0ba902b7');
});

test('sending nothing back is a matter of emptying the array', function () {
    config()->set('tracing.propagation.response_headers', []);

    expect($this->get('/probe')->assertOk()->headers->has('X-Trace-Id'))->toBeFalse();
});

test('an unknown header target is skipped rather than sent empty', function () {
    config()->set('tracing.propagation.response_headers', ['X-Nope' => 'not_a_thing']);

    expect($this->get('/probe')->assertOk()->headers->has('X-Nope'))->toBeFalse();
});
