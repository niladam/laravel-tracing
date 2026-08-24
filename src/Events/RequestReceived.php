<?php

declare(strict_types=1);

namespace Niladam\LaravelTracing\Events;

use Illuminate\Http\Request;

/**
 * A traced request has arrived.
 *
 * Laravel has no event this early — the ones it does publish fire after
 * routing or after the response — so the package announces the moment itself,
 * letting request recorders be listeners like every other kind.
 */
class RequestReceived
{
    public function __construct(public readonly Request $request) {}
}
