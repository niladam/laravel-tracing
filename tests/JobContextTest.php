<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

test('a job records which job it is', function () {
    dispatch(new ProbeJob)->onConnection('sync');

    expect(Cache::get('probe.context'))->toMatchArray([
        'job.name' => ProbeJob::class,
        'job.connection' => 'sync',
        'job.attempts' => 1,
    ])->and(Cache::get('probe.context')['job.queue'])->not->toBeNull();
});

test('a queued closure is recorded without blowing up on reflection', function () {
    dispatch(function () {
        Cache::put('probe.closure', Context::get('job.name'));
    })->onConnection('sync');

    expect(Cache::get('probe.closure'))->toBe(CallQueuedClosure::class);
});

test('job details never reach the payload of a job it dispatches', function () {
    dispatch(new ProbeJob)->onConnection('sync');

    $dehydrated = Cache::get('probe.dehydrated');

    expect($dehydrated)->toHaveKey('trace_id')
        ->and(array_keys($dehydrated))->each->not->toStartWith('job.');
});

test('job context reaches the log line', function () {
    dispatch(new ProbeJob)->onConnection('sync');

    expect(probeRecords()[0]->context)->toMatchArray(['job.name' => ProbeJob::class]);
});

class ProbeJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Log::channel('probe')->info('inside the job');

        Cache::put('probe.context', Context::all());
        Cache::put('probe.dehydrated', Context::dehydrate()['data']);

        Context::add('handled', true);
    }
}

test('job arguments are recorded only when asked for', function () {
    dispatch(new SecretiveJob('inv-1', 'tok_live_abc'))->onConnection('sync');

    expect(Cache::get('probe.context'))->not->toHaveKey('job.arguments.invoiceId');

    config()->set('tracing.context.jobs.arguments', true);

    dispatch(new SecretiveJob('inv-1', 'tok_live_abc'))->onConnection('sync');

    expect(Cache::get('probe.context'))->toHaveKey('job.arguments.invoiceId');
});

test('a parameter marked SensitiveParameter never reaches the context', function () {
    config()->set('tracing.context.jobs.arguments', true);

    dispatch(new SecretiveJob('inv-1', 'tok_live_abc'))->onConnection('sync');

    $context = Cache::get('probe.context');

    expect($context['job.arguments.invoiceId'])->toBe('inv-1')
        ->and($context)->not->toHaveKey('job.arguments.cardToken')
        ->and(json_encode($context))->not->toContain('tok_live_abc');
});

test('remaining arguments still pass through redaction', function () {
    config()->set('tracing.context.jobs.arguments', true);

    dispatch(new SecretiveJob('inv-1', 'tok_live_abc', 'hunter2'))->onConnection('sync');

    expect(Cache::get('probe.context')['job.arguments.password'])->toBe('[redacted]');
});

class SecretiveJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $invoiceId,
        #[SensitiveParameter] public string $cardToken,
        public ?string $password = null,
    ) {}

    public function handle(): void
    {
        Cache::put('probe.context', Context::all());
    }
}
