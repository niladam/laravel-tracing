# Registering the middleware

[← back to the README](../README.md)

`TraceRequests` opens the span an inbound request runs in. It is the single most important piece to get right: **an entry point without it silently starts a fresh trace on every call**, so those requests look unrelated to everything they cause.

## Automatic (the default)

Three independent ways to register it. Use any combination:

```php
'middleware' => [
    'groups' => ['web', 'api'],
    'global' => false,
    'alias'  => 'trace',
],
```

### `groups`

Prepended to each listed group, so it runs before anything else in it — including anything that logs. Groups you have not defined are skipped, so listing one is harmless.

Add your own. If you serve an admin panel on its own group, or a webhook group, or a session-backed API group, each needs listing:

```php
'groups' => ['web', 'api', 'admin', 'webhooks'],
```

### `global`

```php
'global' => true,
```

Prepends it to the global middleware stack, so **every** request is traced — including routes that belong to no group at all. The broadest option, and the one that cannot be defeated by adding a new group later.

Worth knowing: global middleware runs on every request Laravel handles, so this also covers things like health-check routes you may not care about tracing.

### `alias`

```php
'alias' => 'trace',
```

Registers a route alias, so individual routes or controllers can opt in:

```php
Route::middleware('trace')->post('/webhooks/stripe', StripeWebhook::class);
```

Handy when most of your app is traced by group, but one route sits outside them. Set to `null` to skip registering an alias.

## Manual

Empty them all and wire it yourself:

```php
'middleware' => [
    'groups' => [],
    'global' => false,
    'alias'  => null,
],
```

**Laravel 11+** (`bootstrap/app.php`):

```php
use Niladam\LaravelTracing\Http\Middleware\TraceRequests;

->withMiddleware(function (Middleware $middleware) {
    $middleware->prependToGroup('web', TraceRequests::class);
    $middleware->prependToGroup('api', TraceRequests::class);
})
```

Or globally, if you want every route traced including ones outside a group:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->prepend(TraceRequests::class);
})
```

**Laravel 10 and earlier** (`app/Http/Kernel.php`):

```php
protected $middlewareGroups = [
    'web' => [
        TraceRequests::class,
        // …
    ],
];
```

## Ordering

Put it first. Any middleware that runs before it and logs something will do so outside the request's span — the line gets whatever span the process booted with, not the request's.

The one nuance: `TraceRequests` reads the `traceparent` header, which needs no session, no auth and no bindings, so there is nothing it has to run *after*.

## Checking your work

Every group that serves requests should carry it:

```php
test('every route group ingests the trace', function (string $group) {
    expect(app('router')->getMiddlewareGroups()[$group])
        ->toContain(TraceRequests::class);
})->with(['web', 'api', 'admin']);
```

This is worth having in your own suite. Adding a new middleware group is the most common way to end up with an entry point that quietly breaks traces.

## What it does on the way out

On the response, it sets whatever headers you configured — see [Response headers](response-headers.md).
