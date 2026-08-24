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
