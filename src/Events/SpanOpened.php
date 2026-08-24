<?php

declare(strict_types=1);

namespace Niladam\LaravelTracing\Events;

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
    public function __construct(public readonly TraceContext $span) {}
}
