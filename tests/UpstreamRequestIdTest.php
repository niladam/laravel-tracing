<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Niladam\LaravelTracing\Http\Middleware\TraceRequests;

beforeEach(function () {
    Route::middleware(TraceRequests::class)->get('/probe', fn () => 'ok');
});

test('an upstream request id is recorded alongside the trace', function (string $header) {
    $this->get('/probe', [$header => 'edge-abc-123'])->assertOk();

    expect(Context::get('upstream_request_id'))->toBe('edge-abc-123')
        ->and(Context::get('trace_id'))->toMatch('/^[0-9a-f]{32}$/');
})->with(['X-Request-Id', 'CF-Ray']);

test('the first configured header present wins', function () {
    $this->get('/probe', ['X-Request-Id' => 'first', 'CF-Ray' => 'second'])->assertOk();

    expect(Context::get('upstream_request_id'))->toBe('first');
});

test('nothing is recorded when the edge sent no id', function () {
    $this->get('/probe')->assertOk();

    expect(Context::has('upstream_request_id'))->toBeFalse();
});

test('an upstream id never overrides a real traceparent', function () {
    $this->get('/probe', [
        'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
        'X-Request-Id' => 'edge-abc-123',
    ])->assertOk();

    expect(Context::get('trace_id'))->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and(Context::get('upstream_request_id'))->toBe('edge-abc-123');
});
