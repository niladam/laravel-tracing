<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Authenticated;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User as Authenticatable_;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Niladam\LaravelTracing\Facades\Tracing;
use Niladam\LaravelTracing\Http\Middleware\TraceRequests;

class Member extends Authenticatable_
{
    protected $guarded = [];

    public $exists = true;

    public function getAuthIdentifier(): mixed
    {
        return $this->attributes['id'] ?? null;
    }
}

beforeEach(function () {
    Route::middleware(TraceRequests::class)->get('/probe', function () {
        request()->user();          // whatever a real route does

        return Context::all();
    });
});

test('a session guard records the user through the Authenticated event', function () {
    $user = new Member(['id' => 7]);

    Tracing::authenticated('web', fn (Authenticatable $u) => ['tenant_id' => $u->getAuthIdentifier() * 2]);

    event(new Authenticated('web', $user));

    expect(Context::get('user_id'))->toBe(7)
        ->and(Context::get('tenant_id'))->toBe(14);
});

test('a recorder only runs for the guard it was registered against', function () {
    Tracing::authenticated('admin', fn () => ['admin_only' => true]);

    event(new Authenticated('web', new Member(['id' => 7])));

    expect(Context::has('admin_only'))->toBeFalse();

    event(new Authenticated('admin', new Member(['id' => 9])));

    expect(Context::get('admin_only'))->toBeTrue()
        ->and(Context::get('user_id'))->toBe(9);
});

test('a wildcard recorder runs for every guard', function () {
    Tracing::authenticated('*', fn (Authenticatable $u, ?string $guard) => ['via' => $guard]);

    event(new Authenticated('admin', new Member(['id' => 1])));

    expect(Context::get('via'))->toBe('admin');
});

test('a stateless guard is recorded even though it fires no event', function () {
    Auth::viaRequest('stateless-probe', fn () => new Member(['id' => 42]));
    config()->set('auth.guards.probe', ['driver' => 'stateless-probe']);
    config()->set('auth.defaults.guard', 'probe');

    Tracing::authenticated('probe', fn (Authenticatable $u) => ['token_user' => $u->getAuthIdentifier()]);

    $this->get('/probe')->assertOk();

    expect(Context::get('user_id'))->toBe(42)
        ->and(Context::get('token_user'))->toBe(42);
})->note('RequestGuard/TokenGuard never dispatch Authenticated — this is the resolver-wrap path.');

test('no user means no user keys', function () {
    $this->get('/probe')->assertOk();

    expect(Context::has('user_id'))->toBeFalse();
});
