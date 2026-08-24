<?php

declare(strict_types=1);

test('the published config file is loadable and complete', function (string $key) {
    expect(config()->has("tracing.{$key}"))->toBeTrue();
})->with([
    'enabled',
    'middleware.groups',
    'middleware.global',
    'middleware.alias',
    'domains',
    'merge_log_context',
    'flatten_context',
    'redact.keys',
    'redact.replacement',
    'jobs.enabled',
    'jobs.prefix',
    'jobs.arguments',
    'jobs.sensitive_attributes',
    'response.headers',
    'inbound.request_id_headers',
    'keys',
    'never_queue',
]);

test('the config file parses and returns an array', function () {
    $config = require __DIR__.'/../config/tracing.php';

    expect($config)->toBeArray()->not->toBeEmpty();
});
