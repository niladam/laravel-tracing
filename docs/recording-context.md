# Recording context

[← back to the README](../README.md)

Every key has a **moment** — the instant it becomes true. Record it then, and you never have to think about middleware order, resolution timing, or whether the user has logged in yet.

## The moments

| Moment | Signal | What is knowable |
| --- | --- | --- |
| a unit of work begins | `SpanOpened` *(package)* | nothing yet — the moment for facts with no moment |
| a request arrives | `RequestReceived` *(package)* | `channel`, `ip`, `url`, `method`, body, query |
| **any guard answers** | `Authenticated`, or the request's user resolver | the user — session *and* stateless |
| a command starts | `CommandStarting` | `channel`, `command` |
| a job starts | `JobProcessing` | `job.name`, `job.queue`, `job.attempts`, … |
| anything else your app knows | **your own events** | whatever that event carries |

Laravel publishes the middle three. The package publishes the two marked *(package)*, because Laravel has nothing early enough — so every recorder, built-in or yours, is the same kind of thing.

## Adding a key — pick the lightest rung

| Your key is… | Do this | Effort |
| --- | --- | --- |
| a constant | `additional_context` in config | no code |
| a value or two, computed | `Tracing::always(...)` | one line |
| tied to a moment | `Tracing::on(SomeEvent::class, ...)` | one line |
| tied to who is logged in | `Tracing::authenticated('web', ...)` | one line |
| growing, or needs dependencies | a `Recorder` class in `record` | a class |

Only the last rung is a class, and only reach for it when a closure has stopped being the right size. Everything else is one line:

```php
// config/tracing.php
'additional_context' => ['deployment' => env('DEPLOYMENT_ID')],

// a service provider's boot()
Tracing::always(fn () => ['host' => gethostname()]);
Tracing::authenticated('web', fn (User $user) => ['company_id' => $user->current_company_id]);
Tracing::on(CurrentCompanyChanged::class, fn ($e) => ['company_id' => $e->companyId]);
```

## What you get for free

A list of recorder classes. Delete a line to switch one off; add a line to switch your own on.

```php
'record' => [
    RecordRequestContext::class,      // channel, ip, url, method
    RecordAuthenticatedUser::class,   // user_id, session and stateless guards alike
    RecordConsoleContext::class,      // channel, command
    RecordJobContext::class,          // job.name, job.queue, job.attempts, …

    App\Tracing\RecordTenantContext::class,   // and yours, registered identically
],

'request_payload' => false,   // body.* and query.* — off, payloads are bulky
```

Plus static keys, for anything that never changes:

```php
'additional_context' => [
    'version' => env('APP_VERSION'),
],
```

Attached to every request, job and command, and carried into the jobs each dispatches. Values must be serialisable so `config:cache` keeps working — anything that has to be worked out at runtime belongs on a recorder.

## Writing a recorder

A recorder names the event it waits for and returns the keys to merge. That is the whole contract:

```php
use Niladam\LaravelTracing\Contracts\Recorder;

final class RecordTenantContext implements Recorder
{
    public static function listensTo(): string
    {
        return TenantResolved::class;
    }

    public function __invoke(TenantResolved $event): array
    {
        return ['tenant_id' => $event->tenant->id];
    }
}
```

Add it to `record` and it is registered exactly like the built-ins — no provider code, and the config stays cacheable because it is a class name. It is resolved from the container, so it can take dependencies.

Returned enums are unwrapped to their scalar value, so `'channel' => Channel::Http` needs no `->value`.

## Adding your own, without a class

Two methods, for when a class is more ceremony than the job needs. Both take a closure **or** the name of an invokable class.

### `Tracing::authenticated()`

```php
Tracing::authenticated('web', fn (User $user) => [
    'company_id' => $user->current_company_id,
]);

Tracing::authenticated('admin', fn (Admin $admin) => ['admin_id' => $admin->id]);

Tracing::authenticated('*', fn ($user, $guard) => ['guard' => $guard]);
```

Registered per guard, and fires the instant *that* guard resolves a user. An admin recorder costs nothing on requests where no admin is involved.

**This works for stateless guards too.** Laravel only dispatches `Authenticated` from `SessionGuard` — Passport, Sanctum and anything else on `RequestGuard` announce nothing. The package wraps the request's user resolver as a second signal, so both are covered.

### `Tracing::on()`

```php
Tracing::on(OrderShipped::class, fn (OrderShipped $event) => [
    'order_id' => $event->order->id,
]);

Tracing::on(OrderShipped::class, ShipmentContext::class);   // when it grows
```

Register these in a service provider's `boot()`, alongside your other event wiring:

```php
public function boot(): void
{
    Tracing::authenticated('web', fn (User $user) => [...]);
    Tracing::on(CurrentCompanyChanged::class, fn ($e) => ['company_id' => $e->companyId]);
}
```

Not in config — closures are not serialisable, and `config:cache` would refuse. This mirrors `Horizon::auth()` and `Telescope::filter()`.

## Keys correct themselves

This is the part a middleware cannot do. Say a user switches company mid-request:

```php
Tracing::authenticated('web', fn (User $user) => ['company_id' => $user->current_company_id]);
Tracing::on(CurrentCompanyChanged::class, fn ($e) => ['company_id' => $e->companyId]);
```

The first sets it when the user resolves. The second **corrects** it the moment the change happens, because `Context::add` overwrites. Every line logged afterwards, and every job dispatched afterwards, carries the new value.

Snapshot the value once in a middleware and it is wrong for the rest of the request.

## When a recorder grows

Reach for an invokable class rather than a longer closure:

```php
final class BillingContext
{
    public function __construct(private readonly Subscriptions $subscriptions) {}

    public function __invoke(User $user): array
    {
        return ['plan' => $this->subscriptions->planFor($user)];
    }
}

Tracing::authenticated('web', BillingContext::class);
```

Resolved from the container, so it can take dependencies. Recorders **stack** — register as many as you like against the same moment, each doing one thing, rather than growing one array.

## When there is no event

Some facts have no moment — a deployment id, a hostname, a pod name. `Tracing::always()` is the one-liner for exactly this:

```php
Tracing::always(fn () => ['host' => gethostname()]);
```

It is sugar over `SpanOpened`, an event the package announces once per request, job run and command — so if the list outgrows a closure, the same thing as a class is:

```php
final class RecordDeployment implements Recorder
{
    public static function listensTo(): string
    {
        return SpanOpened::class;
    }

    public function __invoke(SpanOpened $event): array
    {
        return ['deployment' => config('app.deployment'), 'host' => gethostname()];
    }
}
```

Either way it fires again when a job rehydrates, so the keys survive a queue hop — a job's context is flushed and refilled, and anything not re-recorded would be lost.

If the value is a constant, skip the class entirely and use `additional_context`.

### It describes the process, not the trace

Because it fires *after* a job rehydrates its dispatcher's context, a `SpanOpened` recorder **overwrites** anything propagated under the same key:

```
dispatcher   deployment = 'abc123'   →   queue   →   worker   deployment = 'abc123'
dispatcher   host       = 'web-01'   →   queue   →   worker   host       = 'worker-07'
```

That is the point for facts about *where this unit ran* — the worker's hostname is the honest answer inside a worker. It is wrong for anything meant to travel with the trace: record that at the moment it becomes true instead, and it will propagate untouched.

Rule of thumb: if the answer differs between the dispatching process and the worker, `SpanOpened` is right. If it should be the same in both, it belongs on another moment.

A recorder that opens a span of its own would announce, recurse and take the process down; the package guards against that, so the nested open simply does not announce again.

## A key that never appears

Work backwards through the moment:

1. **Is the recorder registered?** `boot()` in a provider, not config.
2. **Is it the right guard?** `authenticated('web', …)` never fires for `admin`.
3. **Was the fact true yet?** A line logged before auth has no user keys — correct, not a bug.
4. **Is the built-in on?** Check `record.*`.
5. **Was it redacted?** Look for `[redacted]` — see [Keeping secrets out](secrets.md).
