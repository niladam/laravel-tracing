<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Niladam\LaravelTracing\Tests\TestCase;

pest()->extend(TestCase::class)->in(__DIR__);

function probeRecords(): array
{
    return Log::channel('probe')->getLogger()->getHandlers()[0]->getRecords();
}
