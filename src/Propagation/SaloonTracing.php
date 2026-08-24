<?php

declare(strict_types=1);

namespace Niladam\LaravelTracing\Propagation;

use Saloon\Config;
use Saloon\Data\Pipe;
use Saloon\Http\PendingRequest;

/**
 * Saloon propagation, kept apart so the package runs without Saloon installed.
 *
 * Nothing here is autoloaded unless {@see self::isAvailable()} says Saloon is
 * present, so its classes are only ever resolved when they really exist.
 */
class SaloonTracing
{
    /**
     * Saloon throws when a named pipe is registered twice, and its config is
     * static, so a second app boot in the same process must not register again.
     */
    public const PIPE_NAME = 'traceparent';

    public static function isAvailable(): bool
    {
        return class_exists(Config::class);
    }

    public static function register(OutgoingTrace $outgoing): void
    {
        if (self::isRegistered()) {
            return;
        }

        Config::globalMiddleware()->onRequest(
            callable: static function (PendingRequest $request) use ($outgoing): void {
                foreach ($outgoing->headersForUrl($request->getUrl()) as $header => $value) {
                    $request->headers()->add($header, $value);
                }
            },
            name: self::PIPE_NAME,
        );
    }

    protected static function isRegistered(): bool
    {
        foreach (Config::globalMiddleware()->getRequestPipeline()->getPipes() as $pipe) {
            /** @var Pipe $pipe */
            if ($pipe->name === self::PIPE_NAME) {
                return true;
            }
        }

        return false;
    }
}
