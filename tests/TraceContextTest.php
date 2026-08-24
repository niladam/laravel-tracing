<?php

declare(strict_types=1);

use Niladam\LaravelTracing\TraceContext;

test('start mints a sampled root span', function () {
    $span = TraceContext::start();

    expect($span->traceId)->toMatch('/^[0-9a-f]{32}$/')
        ->and($span->spanId)->toMatch('/^[0-9a-f]{16}$/')
        ->and($span->parentSpanId)->toBeNull()
        ->and($span->traceFlags)->toBe('01');
});

test('parse reads a valid traceparent header', function () {
    $span = TraceContext::parse('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01', 'vendor=value');

    expect($span->traceId)->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and($span->spanId)->toBe('00f067aa0ba902b7')
        ->and($span->traceState)->toBe('vendor=value');
});

test('parse accepts a future version and re-emits it as 00', function () {
    expect(TraceContext::parse('01-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01')->toTraceparent())
        ->toBe('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');
});

test('parse rejects unusable headers', function (?string $traceparent) {
    expect(TraceContext::parse($traceparent))->toBeNull();
})->with([
    'absent' => [null],
    'empty' => [''],
    'nonsense' => ['nonsense'],
    'missing field' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7'],
    'uppercase' => ['00-4BF92F3577B34DA6A3CE929D0E0E4736-00F067AA0BA902B7-01'],
    'invalid version ff' => ['ff-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'],
    'all zero trace id' => ['00-00000000000000000000000000000000-00f067aa0ba902b7-01'],
    'all zero span id' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-0000000000000000-01'],
    'trailing whitespace' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01 '],
]);

test('child keeps the trace and parents itself to the current span', function () {
    $parent = TraceContext::parse('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01', 'vendor=value');
    $child = $parent->child();

    expect($child->traceId)->toBe($parent->traceId)
        ->and($child->parentSpanId)->toBe($parent->spanId)
        ->and($child->spanId)->not->toBe($parent->spanId)
        ->and($child->traceState)->toBe('vendor=value');
});

test('child spans of the same parent never collide', function () {
    $parent = TraceContext::start();

    expect($parent->child()->spanId)->not->toBe($parent->child()->spanId);
});
