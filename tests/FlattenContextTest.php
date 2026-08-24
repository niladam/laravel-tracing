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
    config()->set('tracing.logs.flatten', true);

    $context = logWithContext(['filters' => ['status' => 'open', 'tags' => ['a', 'b']]]);

    expect($context)->toMatchArray([
        'filters.status' => 'open',
        'filters.tags.0' => 'a',
        'filters.tags.1' => 'b',
    ])->and(array_keys($context))->not->toContain('filters');
});

test('flattening leaves empty arrays intact, as Arr::dot does', function () {
    config()->set('tracing.logs.flatten', true);

    expect(logWithContext(['body' => [], 'query' => []]))
        ->toMatchArray(['body' => [], 'query' => []]);
});

test('a nested secret is redacted whether or not flattening is on', function (bool $flatten) {
    config()->set('tracing.logs.flatten', $flatten);

    $context = logWithContext(['body' => ['address' => 'Main St 1', 'password' => 'hunter2']]);

    expect($flatten ? $context['body.password'] : $context['body']['password'])->toBe('[redacted]')
        ->and($flatten ? $context['body.address'] : $context['body']['address'])->toBe('Main St 1');
})->with([
    'flattened' => [true],
    'nested' => [false],
]);

test('flattening does not reach job payloads', function () {
    config()->set('tracing.logs.flatten', true);

    Context::add('filters', ['status' => 'open']);

    Log::channel('probe')->info('hello');

    expect(array_keys(probeRecords()[0]->context))->toContain('filters.status')
        ->and(array_keys(Context::dehydrate()['data']))->toContain('filters')
        ->and(Context::get('filters'))->toBe(['status' => 'open']);
});
