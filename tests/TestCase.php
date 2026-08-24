<?php

declare(strict_types=1);

namespace Niladam\LaravelTracing\Tests;

use Monolog\Handler\TestHandler;
use Niladam\LaravelTracing\TracingServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Config applied before the package boots.
     *
     * Set it, call refreshApplication(), and the app comes back up as though
     * the config had always been that way — the only honest way to test what
     * happens at registration time.
     *
     * @var array<string, mixed>
     */
    public array $bootConfig = [];

    protected function getPackageProviders($app): array
    {
        return [TracingServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['router']->middlewareGroup('web', []);
        $app['router']->middlewareGroup('api', []);

        $app['config']->set('session.domain', '.example.test');
        $app['config']->set('tracing.context.local_only', ['body.*']);

        foreach ($this->bootConfig as $key => $value) {
            $app['config']->set($key, $value);
        }
        $app['config']->set('logging.channels.probe', [
            'driver' => 'monolog',
            'handler' => TestHandler::class,
        ]);
    }
}
