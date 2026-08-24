<?php

declare(strict_types=1);

namespace Niladam\LaravelTracing\Listeners;

use Illuminate\Auth\Events\Authenticated;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Niladam\LaravelTracing\Tracing;

/**
 * Records who a unit of work is running as, whichever guard answers.
 *
 * Two signals are needed, because Laravel only announces one of them:
 * {@see Authenticated} is dispatched by SessionGuard alone, so a stateless
 * guard — Passport, Sanctum, anything built on RequestGuard — would otherwise
 * go unrecorded. Wrapping the request's user resolver covers those, and the
 * two together cover every driver.
 */
class RecordAuthenticatedUser
{
    public function __construct(private readonly Tracing $tracing) {}

    /**
     * The session-guard path.
     */
    public function handle(Authenticated $event): void
    {
        $this->record($event->user, $event->guard);
    }

    /**
     * The stateless path: notice the first time any guard answers through the request.
     */
    public function watch(Request $request): void
    {
        $resolver = $request->getUserResolver();

        $request->setUserResolver(function ($guard = null) use ($resolver) {
            $user = $resolver($guard);

            if ($user instanceof Authenticatable) {
                $this->record($user, $guard ?? config('auth.defaults.guard'));
            }

            return $user;
        });
    }

    protected function record(Authenticatable $user, ?string $guard): void
    {
        Context::add('user_id', $user->getAuthIdentifier());

        $this->tracing->recordersFor($guard)->each(
            fn (callable $recorder) => Context::add($recorder($user, $guard) ?: []),
        );
    }
}
