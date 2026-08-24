<?php

declare(strict_types=1);

namespace Niladam\LaravelTracing;

/**
 * What kind of work produced a log line.
 *
 * One key answering the question, rather than a boolean per channel that has
 * to be read in combination to work out where a line came from.
 */
enum Channel: string
{
    case Http = 'http';
    case Console = 'console';
    case Queue = 'queue';
}
