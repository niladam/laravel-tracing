<?php

declare(strict_types=1);

namespace Niladam\LaravelTracing\Events;

use Illuminate\Support\Facades\Event;
use Niladam\LaravelTracing\TraceContext;

/**
 * A unit of work has begun — a request, a job run, a command, a console boot.
 *
 * The moment for context that has no moment of its own: a deployment id, a
 * hostname, whatever a container happens to know. Listening here means such a
 * recorder is written and registered exactly like every other one, rather than
 * being a second kind of thing with its own rules.
 */
class SpanOpened
{
    /**
     * Guards a recorder that opens a span of its own, which would otherwise
     * announce itself, recurse, and take the process down with it.
     */
    private static bool $announcing = false;

    public function __construct(public readonly TraceContext $span) {}

    public static function announce(TraceContext $span): void
    {
        if (self::$announcing) {
            return;
        }

        self::$announcing = true;

        try {
            Event::dispatch(new self($span));
        } finally {
            self::$announcing = false;
        }
    }
}
