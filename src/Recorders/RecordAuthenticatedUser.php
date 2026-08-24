<?php

declare(strict_types=1);

namespace Niladam\LaravelTracing\Recorders;

use Illuminate\Auth\Events\Authenticated;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Niladam\LaravelTracing\Contracts\Recorder;
use Niladam\LaravelTracing\Tracing;

/**
 * Records who a unit of work is running as, whichever guard answers.
 *
 * Two signals are needed, because Laravel only announces one of them:
 * {@see Authenticated} is dispatched by SessionGuard alone, so a stateless
 * guard — Passport, Sanctum, anything built on RequestGuard — would otherwise
 * go unrecorded. {@see self::watch()} covers those, and the two together cover
 * every driver.
 */
class RecordAuthenticatedUser implements Recorder
{
    public function __construct(private readonly Tracing $tracing) {}

    public static function listensTo(): string
    {
        return Authenticated::class;
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(object $event): array
    {
        return [
            'user_id' => $event->user->getAuthIdentifier(),
            ...$this->recorded($event->user, $event->guard),
        ];
    }

    /**
     * Notice the first time any guard answers through the request.
     *
     * The stateless path, since those guards dispatch nothing.
     */
    public function watch(Request $request): void
    {
        $resolver = $request->getUserResolver();

        $request->setUserResolver(function ($guard = null) use ($resolver) {
            $user = $resolver($guard);

            if ($user instanceof Authenticatable) {
                event(new Authenticated($guard ?? config('auth.defaults.guard'), $user));
            }

            return $user;
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function recorded(Authenticatable $user, ?string $guard): array
    {
        return $this->tracing->recordersFor($guard)
            ->flatMap(fn (callable $recorder) => $recorder($user, $guard) ?: [])
            ->all();
    }
}
