<?php

declare(strict_types=1);

namespace Niladam\LaravelTracing\Tests;

use Monolog\Handler\TestHandler;
use Niladam\LaravelTracing\TracingServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [TracingServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['router']->middlewareGroup('web', []);
        $app['router']->middlewareGroup('api', []);

        $app['config']->set('session.domain', '.example.test');
        $app['config']->set('tracing.never_queue', ['body.*']);
        $app['config']->set('logging.channels.probe', [
            'driver' => 'monolog',
            'handler' => TestHandler::class,
        ]);
    }
}
