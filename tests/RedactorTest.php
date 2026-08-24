<?php

declare(strict_types=1);

use Niladam\LaravelTracing\Redactor;

test('it masks keys matching a pattern and leaves the rest alone', function () {
    $redactor = new Redactor(['*password*', '*token*'], '[redacted]');

    expect($redactor->apply([
        'company_id' => 8,
        'body.password' => 'hunter2',
        'body.password_confirmation' => 'hunter2',
        'API_TOKEN' => 'abc',
        'trace_id' => 'keepme',
    ]))->toBe([
        'company_id' => 8,
        'body.password' => '[redacted]',
        'body.password_confirmation' => '[redacted]',
        'API_TOKEN' => '[redacted]',
        'trace_id' => 'keepme',
    ]);
});

test('it does nothing without patterns', function () {
    expect((new Redactor([], '[redacted]'))->apply(['password' => 'hunter2']))
        ->toBe(['password' => 'hunter2']);
});

test('it descends into nested arrays', function () {
    $redactor = new Redactor(['*password*', '*token*'], '[redacted]');

    expect($redactor->apply([
        'company_id' => 8,
        'body' => [
            'address' => 'Main St 1',
            'password' => 'hunter2',
            'nested' => ['api_token' => 'abc'],
        ],
    ]))->toBe([
        'company_id' => 8,
        'body' => [
            'address' => 'Main St 1',
            'password' => '[redacted]',
            'nested' => ['api_token' => '[redacted]'],
        ],
    ]);
});

test('a pattern can target a full dotted path', function () {
    $redactor = new Redactor(['body.address'], '[redacted]');

    expect($redactor->apply(['body' => ['address' => 'Main St 1', 'city' => 'Cluj']]))
        ->toBe(['body' => ['address' => '[redacted]', 'city' => 'Cluj']]);
});

test('a redacted branch is not descended into', function () {
    $redactor = new Redactor(['*secret*'], '[redacted]');

    expect($redactor->apply(['secret_bag' => ['a' => 1, 'b' => 2]]))
        ->toBe(['secret_bag' => '[redacted]']);
});

test('it reports the key paths it masked', function () {
    $redactor = new Redactor(['*password*', '*token*'], '[redacted]');

    $redactor->apply([
        'company_id' => 8,
        'body' => ['password' => 'hunter2', 'nested' => ['api_token' => 'abc']],
    ], $redacted);

    expect($redacted)->toBe(['body.password', 'body.nested.api_token']);
});

test('it reports nothing when nothing matched', function () {
    (new Redactor(['*password*'], '[redacted]'))->apply(['company_id' => 8], $redacted);

    expect($redacted)->toBe([]);
});
