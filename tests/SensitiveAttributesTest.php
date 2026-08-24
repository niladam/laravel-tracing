<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Niladam\LaravelTracing\SensitiveParameters;

beforeEach(fn () => config()->set('tracing.jobs.arguments', true));

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
    config()->set('tracing.jobs.sensitive_attributes', [ExcludeFromLogs::class]);
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
