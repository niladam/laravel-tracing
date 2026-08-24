<?php

declare(strict_types=1);

namespace Niladam\LaravelTracing\Recorders;

use Illuminate\Console\Events\CommandStarting;
use Niladam\LaravelTracing\Channel;
use Niladam\LaravelTracing\Contracts\Recorder;

/**
 * Records which command a line was logged from.
 *
 * A long-running process handles many commands; without this they all report
 * the span the process booted with and nothing says which is which.
 */
class RecordConsoleContext implements Recorder
{
    public static function listensTo(): string
    {
        return CommandStarting::class;
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(object $event): array
    {
        return [
            'channel' => Channel::Console,
            'command' => $event->command ?? $event->input->getFirstArgument(),
        ];
    }
}
