<?php

declare(strict_types=1);

namespace Niladam\LaravelTracing\Listeners;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Context;
use Niladam\LaravelTracing\Channel;

/**
 * Records which command a line was logged from.
 *
 * A long-running process handles many commands; without this they all report
 * the span the process booted with and nothing says which is which.
 */
class RecordConsoleContext
{
    public function handle(CommandStarting $event): void
    {
        Context::add([
            'channel' => Channel::Console->value,
            'command' => $event->command ?? $event->input->getFirstArgument(),
        ]);
    }
}
