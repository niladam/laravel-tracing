<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Niladam\LaravelTracing\Facades\Tracing;
use Niladam\LaravelTracing\SensitiveParameters;

beforeEach(fn () => config()->set('tracing.context.jobs.arguments', true));

test('a custom attribute is honoured alongside SensitiveParameter', function () {
    app()->instance(SensitiveParameters::class, new SensitiveParameters([
        SensitiveParameter::class,
        ExcludeFromLogs::class,
    ]));

    dispatch(new TwoAttributeJob('inv-1', 'tok_live', 'internal'))->onConnection('sync');

    $context = Cache::get('probe.attrs');

    expect($context['job.arguments.invoiceId'])->toBe('inv-1')
        ->and($context)->not->toHaveKey('job.arguments.cardToken')
        ->and($context)->not->toHaveKey('job.arguments.note');
});

test('an attribute that is not listed is not honoured', function () {
    app()->instance(SensitiveParameters::class, new SensitiveParameters([SensitiveParameter::class]));

    dispatch(new TwoAttributeJob('inv-1', 'tok_live', 'internal'))->onConnection('sync');

    expect(Cache::get('probe.attrs')['job.arguments.note'])->toBe('internal');
});

test('the attribute list is driven by config', function () {
    config()->set('tracing.context.jobs.sensitive_attributes', [ExcludeFromLogs::class]);
    app()->forgetInstance(SensitiveParameters::class);

    dispatch(new TwoAttributeJob('inv-1', 'tok_live', 'internal'))->onConnection('sync');

    $context = Cache::get('probe.attrs');

    expect($context)->not->toHaveKey('job.arguments.note')
        ->and($context)->toHaveKey('job.arguments.cardToken')
        ->and($context['job.arguments.cardToken'])->toBe('[redacted]');
})->note('SensitiveParameter is unlisted, so cardToken is no longer dropped — but "*token*" still redacts it.');

test('an empty attribute list disables the mechanism', function () {
    expect((new SensitiveParameters([]))->for(TwoAttributeJob::class))->toBe([]);
});

#[Attribute(Attribute::TARGET_PARAMETER)]
class ExcludeFromLogs {}

class TwoAttributeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $invoiceId,
        #[SensitiveParameter] public string $cardToken,
        #[ExcludeFromLogs] public string $note,
    ) {}

    public function handle(): void
    {
        Cache::put('probe.attrs', Context::all());
    }
}

test('the package names what it withheld, so absence is not ambiguous', function () {
    dispatch(new TwoAttributeJob('inv-1', 'tok_live', 'internal'))->onConnection('sync');

    $context = Cache::get('probe.attrs');

    expect($context['job.excluded_parameters'])->toBe(['cardToken'])
        ->and($context)->not->toHaveKey('job.arguments.cardToken');
});

test('nothing withheld means no excluded_parameters key at all', function () {
    dispatch(new OpenJob('inv-1'))->onConnection('sync');

    expect(Cache::get('probe.attrs'))->not->toHaveKey('job.excluded_parameters');
});

test('the list is reachable, so an app need not reimplement the reflection', function () {
    expect(Tracing::sensitiveParametersFor(TwoAttributeJob::class))
        ->toBe(['cardToken'])
        ->and(Tracing::sensitiveParametersFor(OpenJob::class))
        ->toBe([]);
});

class OpenJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $invoiceId) {}

    public function handle(): void
    {
        Cache::put('probe.attrs', Context::all());
    }
}

test('naming the withheld parameters can be switched off', function () {
    config()->set('tracing.context.jobs.name_excluded_parameters', false);

    dispatch(new TwoAttributeJob('inv-1', 'tok_live', 'internal'))->onConnection('sync');

    $context = Cache::get('probe.attrs');

    expect($context)->not->toHaveKey('job.excluded_parameters')
        ->and($context)->not->toHaveKey('job.arguments.cardToken')
        ->and($context['job.arguments.invoiceId'])->toBe('inv-1');
})->note('Switching it off hides the name, never the withholding itself.');
