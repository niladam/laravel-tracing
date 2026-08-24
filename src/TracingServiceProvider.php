<?php

declare(strict_types=1);

namespace Niladam\LaravelTracing;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Log\ContextLogProcessor as ContextLogProcessorContract;
use Illuminate\Log\Context\Events\ContextHydrated;
use Illuminate\Log\Context\Repository;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Niladam\LaravelTracing\Http\Middleware\TraceRequests;
use Niladam\LaravelTracing\Listeners\RecordJobContext;
use Niladam\LaravelTracing\Logging\ContextLogProcessor;
use Niladam\LaravelTracing\Propagation\OutgoingTrace;
use Niladam\LaravelTracing\Propagation\SaloonTracing;
use Psr\Http\Message\RequestInterface;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class TracingServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-tracing')
            ->hasConfigFile('tracing');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Tracing::class);

        $this->app->singleton(ContextKeys::class, fn () => ContextKeys::fromArray(
            (array) config('tracing.keys', []),
        ));

        $this->app->singleton(Redactor::class, fn () => new Redactor(
            patterns: (array) config('tracing.redact.keys', []),
            replacement: (string) config('tracing.redact.replacement', '[redacted]'),
        ));

        $this->app->singleton(OutgoingTrace::class, fn () => new OutgoingTrace(
            domains: $this->propagatedDomains(),
        ));

        if (! $this->tracingEnabled()) {
            return;
        }

        if (config('tracing.merge_log_context')) {
            $this->app->bind(ContextLogProcessorContract::class, ContextLogProcessor::class);
        }
    }

    public function packageBooted(): void
    {
        if (! $this->tracingEnabled()) {
            return;
        }

        Event::listen(events: ContextHydrated::class, listener: fn () => $this->startChildSpan());

        $this->recordJobContext();
        $this->keepSensitiveContextOutOfJobs();
        $this->registerMiddleware();
        $this->traceOutgoingRequests();

        $this->app->booted(fn () => $this->ensureContextHasTracingData());
    }

    /**
     * Open the span this process runs in.
     *
     * An HTTP request narrows this down again in {@see TraceRequests}, once the
     * inbound `traceparent` is readable; seeding it here means anything logged
     * before that middleware still carries a trace.
     */
    protected function ensureContextHasTracingData(): void
    {
        if (Context::has(ContextKeys::resolve()->traceId)) {
            return;
        }

        TraceContext::start()->putInContext();
    }

    /**
     * Open a span for the job that just rehydrated the dispatching side's context.
     *
     * {@see Repository::hydrate()} flushes the repository before refilling it, so
     * without this a job would keep reporting the dispatcher's span — or, on a
     * worker, the span it happened to boot with.
     */
    protected function startChildSpan(): void
    {
        $span = TraceContext::fromContext()?->child() ?? TraceContext::start();

        $span->putInContext();
    }

    /**
     * Record which job each line was logged from.
     */
    protected function recordJobContext(): void
    {
        if (config('tracing.jobs.enabled', true)) {
            Event::listen(JobProcessing::class, RecordJobContext::class);
        }
    }

    /**
     * Context is serialised into every job payload, so anything the application
     * does not want written to its queue is dropped on the way in. The running
     * process keeps it: only the copy bound for the payload is trimmed.
     *
     * Job details are always dropped: a job's children should carry their own,
     * not inherit the ones belonging to whatever dispatched them.
     */
    protected function keepSensitiveContextOutOfJobs(): void
    {
        $patterns = [
            ...(array) config('tracing.never_queue', []),
            config('tracing.jobs.prefix', 'job').'.*',
        ];

        Context::dehydrating(function (Repository $context) use ($patterns): void {
            foreach (array_keys($context->all()) as $key) {
                if (Str::is($patterns, Str::lower((string) $key))) {
                    $context->forget($key);
                }
            }
        });
    }

    /**
     * Put {@see TraceRequests} wherever the application wants it, ahead of
     * anything that might log before the trace exists.
     */
    protected function registerMiddleware(): void
    {
        if (! $this->app->bound(Router::class)) {
            return;
        }

        $router = $this->app->make(Router::class);

        foreach ((array) config('tracing.middleware.groups', []) as $group) {
            if ($router->hasMiddlewareGroup($group)) {
                $router->prependMiddlewareToGroup($group, TraceRequests::class);
            }
        }

        if ($alias = config('tracing.middleware.alias')) {
            $router->aliasMiddleware($alias, TraceRequests::class);
        }

        if (config('tracing.middleware.global', false)) {
            $this->registerGlobalMiddleware();
        }
    }

    /**
     * Trace every request, including routes that belong to no group at all.
     */
    protected function registerGlobalMiddleware(): void
    {
        if (! $this->app->bound(Kernel::class)) {
            return;
        }

        $kernel = $this->app->make(Kernel::class);

        if (method_exists($kernel, 'prependMiddleware')) {
            $kernel->prependMiddleware(TraceRequests::class);
        }
    }

    /**
     * Hand the current span to every HTTP client the application sends with.
     *
     * Saloon ships its own sender, so {@see Http::globalRequestMiddleware()}
     * never sees its requests; it is registered separately when installed.
     */
    protected function traceOutgoingRequests(): void
    {
        $outgoing = $this->app->make(OutgoingTrace::class);

        Http::globalRequestMiddleware(function (RequestInterface $request) use ($outgoing): RequestInterface {
            foreach ($outgoing->headersFor($request->getUri()->getHost()) as $header => $value) {
                $request = $request->withHeader($header, $value);
            }

            return $request;
        });

        if (SaloonTracing::isAvailable()) {
            SaloonTracing::register($outgoing);
        }
    }

    protected function tracingEnabled(): bool
    {
        return (bool) config('tracing.enabled', true);
    }

    /**
     * Falls back to the session domain, which is already the boundary every
     * subdomain of the application shares.
     *
     * @return list<string>
     */
    protected function propagatedDomains(): array
    {
        $domains = config('tracing.domains') ?: [config('session.domain')];

        return array_values(array_filter(array_map(
            fn (mixed $domain) => ltrim((string) $domain, '.'),
            (array) $domains,
        )));
    }
}
