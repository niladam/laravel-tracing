<?php

declare(strict_types=1);

use Niladam\LaravelTracing\Facades\Tracing;
use Niladam\LaravelTracing\Propagation\SaloonTracing;
use Niladam\LaravelTracing\TraceContext;
use Saloon\Config;
use Saloon\Enums\Method;
use Saloon\Http\Connector;
use Saloon\Http\Request;

beforeEach(fn () => SaloonTracing::isAvailable() ?: $this->markTestSkipped('Saloon is not installed.'));

function saloonHeadersFor(string $baseUrl): array
{
    $connector = new class($baseUrl) extends Connector
    {
        public function __construct(private readonly string $baseUrl) {}

        public function resolveBaseUrl(): string
        {
            return $this->baseUrl;
        }
    };

    $request = new class extends Request
    {
        protected Method $method = Method::GET;

        public function resolveEndpoint(): string
        {
            return '/ping';
        }
    };

    return $connector->createPendingRequest($request)->headers()->all();
}

test('saloon requests propagate the current span to our own hosts', function () {
    TraceContext::parse('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01', 'vendor=value')
        ->child()
        ->putInContext();

    expect(saloonHeadersFor('https://api.example.test'))
        ->toMatchArray([
            'traceparent' => Tracing::traceparent(),
            'tracestate' => 'vendor=value',
        ]);
});

test('saloon requests to third parties leak nothing', function () {
    TraceContext::start()->putInContext();

    expect(saloonHeadersFor('https://api.stripe.com'))
        ->not->toHaveKeys(['traceparent', 'tracestate']);
});

test('the pipe is registered once, even across app boots', function () {
    $named = array_filter(
        Config::globalMiddleware()->getRequestPipeline()->getPipes(),
        fn ($pipe) => $pipe->name === SaloonTracing::PIPE_NAME,
    );

    expect($named)->toHaveCount(1);
});
