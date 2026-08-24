<?php

declare(strict_types=1);

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Niladam\LaravelTracing\Channel;
use Niladam\LaravelTracing\Contracts\Recorder;
use Niladam\LaravelTracing\Events\SpanOpened;
use Niladam\LaravelTracing\Facades\Tracing;
use Niladam\LaravelTracing\Http\Middleware\TraceRequests;
use Niladam\LaravelTracing\Recorders\RecordRequestContext;
use Niladam\LaravelTracing\TraceContext;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

beforeEach(function () {
    Route::middleware(TraceRequests::class)->post('/probe', fn () => Context::all());
});

test('a request records what it was', function () {
    $this->post('/probe?page=2', ['note' => 'hi'])
        ->assertOk()
        ->assertJsonPath('channel', Channel::Http->value)
        ->assertJsonPath('method', 'POST')
        ->assertJsonPath('ip', '127.0.0.1');

    expect(Context::get('url'))->toContain('/probe?page=2');
});

test('the request payload is left out by default', function () {
    $context = $this->post('/probe?page=2', ['note' => 'hi'])->assertOk()->json();

    expect($context)->not->toHaveKey('body.note')
        ->and($context)->not->toHaveKey('query.page');
});

test('the request payload is recorded when asked for, as dot keys', function () {
    config()->set('tracing.context.request_payload', true);

    $context = $this->post('/probe?page=2', ['note' => 'hi'])->assertOk()->json();

    expect($context['body.note'])->toBe('hi')
        ->and($context['query.page'])->toBe('2');
});

test('a recorded payload still passes through redaction', function () {
    config()->set('tracing.context.request_payload', true);

    $this->post('/probe', ['password' => 'hunter2'])->assertOk();

    Log::channel('probe')->info('hello');

    expect(probeRecords()[0]->context['body.password'])->toBe('[redacted]');
});

test('a console command records what it was', function () {
    event(new CommandStarting('queue:work', new ArrayInput([]), new NullOutput));

    expect(Context::get('channel'))->toBe(Channel::Console->value)
        ->and(Context::get('command'))->toBe('queue:work');
});

test('a job records the queue channel', function () {
    dispatch(function () {
        Cache::put('probe.channel', Context::get('channel'));
    })->onConnection('sync');

    expect(Cache::get('probe.channel'))->toBe(Channel::Queue->value);
});

test('additional context is attached to a request', function () {
    config()->set('tracing.context.additional', ['version' => '1.2.3']);

    $this->post('/probe')->assertOk()->assertJsonPath('version', '1.2.3');
});

test('additional context survives into a job, whose context is flushed on hydrate', function () {
    config()->set('tracing.context.additional', ['version' => '1.2.3']);

    TraceContext::start()->putInContext();
    Context::hydrate(Context::dehydrate());

    expect(Context::get('version'))->toBe('1.2.3');
});

test('a recorder left out of the list never registers', function () {
    $this->bootConfig = ['tracing.record' => array_values(array_diff(
        config('tracing.record'),
        [RecordRequestContext::class],
    ))];

    $this->refreshApplication();

    Route::middleware(TraceRequests::class)->post('/probe', fn () => Context::all());

    $context = $this->post('/probe')->assertOk()->json();

    expect($context)->not->toHaveKey('method')
        ->and($context)->not->toHaveKey('channel')
        ->and($context)->toHaveKey('trace_id');
})->note('The trace itself still works — only that one recorder is gone.');

test('a recorder with no natural event can listen for the span opening', function () {
    $this->bootConfig = ['tracing.record' => [...config('tracing.record'), RecordDeployment::class]];
    $this->refreshApplication();

    Route::middleware(TraceRequests::class)->post('/probe', fn () => Context::all());

    expect($this->post('/probe')->assertOk()->json())
        ->toHaveKey('deployment', 'abc123');
});

test('the span opening reaches a job too, whose context is flushed on hydrate', function () {
    $this->bootConfig = ['tracing.record' => [...config('tracing.record'), RecordDeployment::class]];
    $this->refreshApplication();

    Context::hydrate(Context::dehydrate());

    expect(Context::get('deployment'))->toBe('abc123');
});

final class RecordDeployment implements Recorder
{
    public static function listensTo(): string
    {
        return SpanOpened::class;
    }

    public function __invoke(object $event): array
    {
        return ['deployment' => 'abc123'];
    }
}

test('a span recorder describes this process, so it wins over propagated context', function () {
    $this->bootConfig = ['tracing.record' => [...config('tracing.record'), RecordDeployment::class]];
    $this->refreshApplication();

    TraceContext::start()->putInContext();
    Context::add('deployment', 'the-dispatchers-value');

    Context::hydrate(Context::dehydrate());

    expect(Context::get('deployment'))->toBe('abc123');
})->note('Deliberate: SpanOpened describes where this unit ran, not where the trace began.');

test('a recorder that opens a span of its own does not recurse', function () {
    $this->bootConfig = ['tracing.record' => [...config('tracing.record'), RecordThatOpensASpan::class]];
    $this->refreshApplication();

    Context::flush();

    TraceContext::start()->putInContext();

    expect(Context::get('reentered'))->toBe(1);
})->note('The nested putInContext() must not announce again — one span open, one run.');

final class RecordThatOpensASpan implements Recorder
{
    public static function listensTo(): string
    {
        return SpanOpened::class;
    }

    public function __invoke(object $event): array
    {
        TraceContext::start()->putInContext();

        return ['reentered' => (int) Context::get('reentered') + 1];
    }
}

test('always is the one-line way to add a key with no moment of its own', function () {
    Tracing::always(fn () => ['deployment' => 'from-a-closure']);

    Context::flush();
    TraceContext::start()->putInContext();

    expect(Context::get('deployment'))->toBe('from-a-closure');
});

test('always reaches jobs too', function () {
    Tracing::always(fn () => ['deployment' => 'from-a-closure']);

    TraceContext::start()->putInContext();
    Context::hydrate(Context::dehydrate());

    expect(Context::get('deployment'))->toBe('from-a-closure');
});

test('a recorder is resolved from the container, so it can take dependencies', function () {
    $this->bootConfig = ['tracing.record' => [...config('tracing.record'), RecordWithDependency::class]];
    $this->refreshApplication();

    Context::flush();
    TraceContext::start()->putInContext();

    expect(Context::get('greeting'))->toBe('resolved');
});

final class GreetingProbe
{
    public function __construct(public readonly string $value = 'resolved') {}
}

final class RecordWithDependency implements Recorder
{
    public function __construct(private readonly GreetingProbe $probe) {}

    public static function listensTo(): string
    {
        return SpanOpened::class;
    }

    public function __invoke(object $event): array
    {
        return ['greeting' => $this->probe->value];
    }
}
