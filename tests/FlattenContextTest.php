<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

function logWithContext(array $context): array
{
    Context::add($context);

    Log::channel('probe')->info('hello');

    return probeRecords()[0]->context;
}

test('nested context is left alone by default', function () {
    $context = logWithContext(['filters' => ['status' => 'open', 'tags' => ['a', 'b']]]);

    expect($context['filters'])->toBe(['status' => 'open', 'tags' => ['a', 'b']])
        ->and(array_keys($context))->not->toContain('filters.status');
});

test('nested context becomes dot keys when flattening is on', function () {
    config()->set('tracing.flatten_context', true);

    $context = logWithContext(['filters' => ['status' => 'open', 'tags' => ['a', 'b']]]);

    expect($context)->toMatchArray([
        'filters.status' => 'open',
        'filters.tags.0' => 'a',
        'filters.tags.1' => 'b',
    ])->and(array_keys($context))->not->toContain('filters');
});

test('flattening leaves empty arrays intact, as Arr::dot does', function () {
    config()->set('tracing.flatten_context', true);

    expect(logWithContext(['body' => [], 'query' => []]))
        ->toMatchArray(['body' => [], 'query' => []]);
});

test('flattening exposes a nested secret to redaction', function () {
    config()->set('tracing.flatten_context', true);

    expect(logWithContext(['body' => ['address' => 'Main St 1', 'password' => 'hunter2']]))
        ->toMatchArray(['body.address' => 'Main St 1', 'body.password' => '[redacted]']);
});

test('without flattening a nested secret slips past redaction', function () {
    $context = logWithContext(['body' => ['password' => 'hunter2']]);

    expect($context['body']['password'])->toBe('hunter2');
})->note('Documents why flatten runs before redact — the key checked is "body", not "body.password".');

test('flattening does not reach job payloads', function () {
    config()->set('tracing.flatten_context', true);

    Context::add('filters', ['status' => 'open']);

    Log::channel('probe')->info('hello');

    expect(array_keys(probeRecords()[0]->context))->toContain('filters.status')
        ->and(array_keys(Context::dehydrate()['data']))->toContain('filters')
        ->and(Context::get('filters'))->toBe(['status' => 'open']);
});
