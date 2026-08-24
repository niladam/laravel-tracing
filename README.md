# Laravel Tracing

[![Latest Version on Packagist](https://img.shields.io/packagist/v/niladam/laravel-tracing.svg?style=flat-square)](https://packagist.org/packages/niladam/laravel-tracing)
[![Tests](https://img.shields.io/github/actions/workflow/status/niladam/laravel-tracing/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/niladam/laravel-tracing/actions/workflows/run-tests.yml)
[![Code Style](https://img.shields.io/github/actions/workflow/status/niladam/laravel-tracing/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/niladam/laravel-tracing/actions/workflows/fix-php-code-style-issues.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/niladam/laravel-tracing.svg?style=flat-square)](https://packagist.org/packages/niladam/laravel-tracing)

One id follows a request through its own log lines, the queued jobs it dispatches, the jobs *those* dispatch, and the internal services it calls.

Built on the [W3C Trace Context](https://www.w3.org/TR/trace-context/) standard, so it speaks the same `traceparent` header as OpenTelemetry, Sentry, Datadog and Honeycomb — no APM dependency required, and nothing to rewrite if you add one later.

```
[13:34:12] local.INFO: order placed      {"trace_id":"d4f871…","span_id":"9215fefcbdd8b18e","parent_span_id":null,…}
[13:34:13] local.INFO: charging card     {"trace_id":"d4f871…","span_id":"16d7e9be2ca54e25","parent_span_id":"9215fefcbdd8b18e",…}
[13:34:13] local.INFO: sending receipt   {"trace_id":"d4f871…","span_id":"5b18f3563067f4f8","parent_span_id":"16d7e9be2ca54e25",…}
```

`grep` one `trace_id` for the whole tree. `parent_span_id` tells you who triggered what.

## Installation

```bash
composer require niladam/laravel-tracing
```

That is the whole setup — the provider is auto-discovered, requests are traced, jobs inherit the trace and log lines carry it. Publish the config only when you want to change something:

```bash
php artisan vendor:publish --tag=laravel-tracing-config
```

## What you get without configuring anything

| Key | |
| --- | --- |
| `trace_id` | 32 hex. The root, minted once at the origin and **never** regenerated as it travels. |
| `span_id` | 16 hex. This one unit of work — this request, this job run, this command. |
| `parent_span_id` | The `span_id` that caused this one. `null` at the root. |
| `channel` | `http`, `console` or `queue`. |
| `user_id` | The moment any guard answers — session and stateless alike. |
| `ip`, `url`, `method` | On a request. |
| `command` | On a console command. |
| `job.*` | `name`, `connection`, `queue`, `attempts`, `uuid` — inside a job. |

Crossing every boundary on the way:

| Boundary | How |
| --- | --- |
| Inbound HTTP | Reads `traceparent` / `tracestate` and continues the caller's trace |
| Queued jobs | Context dehydrate/hydrate, with a fresh child span per job run |
| Console subprocesses | Laravel rehydrates `__LARAVEL_CONTEXT`, so `Artisan::call` children join in |
| Outgoing HTTP | `traceparent` injected on your own hosts only — never a third party |
| Saloon | Registered separately, since it ships its own sender. Optional. |
| Logs | Merged into each record's `context`, so a line stays one JSON object |

## Adding your own context

Every key has a **moment** — the instant it becomes true. Record it then and you never think about middleware order or whether the user has logged in yet.

Pick the lightest rung that fits:

| Your key is… | Do this |
| --- | --- |
| a constant | `context.additional` in config — no code |
| a value or two, computed | `Tracing::always(…)` |
| tied to who is logged in | `Tracing::authenticated('web', …)` |
| tied to a moment | `Tracing::on(SomeEvent::class, …)` |
| growing, or needs dependencies | a `Recorder` class listed in `record` |

```php
use Niladam\LaravelTracing\Facades\Tracing;

// in a service provider's boot()
Tracing::always(fn () => ['host' => gethostname()]);

Tracing::authenticated('web', fn (User $user) => [
    'team_id' => $user->current_team_id,
]);

Tracing::on(TeamSwitched::class, fn ($e) => ['team_id' => $e->team->id]);
```

That last line is the part a middleware cannot do: `Context::add` overwrites, so the key **corrects itself** the moment the team changes. Snapshot it once at the start of a request and it is wrong for the rest of it.

Anything you put in Laravel's own `Context` is traced too — there is no second store and nothing new to learn:

```php
Context::add(['order_id' => $order->id]);
Context::addHidden('idempotency_key', $key);   // travels to jobs, never logged
```

Read the trace back through the facade, which knows what you have named the keys:

```php
Tracing::traceId();       // 4bf92f3577b34da6a3ce929d0e0e4736
Tracing::traceparent();   // to hand to a client this package does not cover
```

## Keeping secrets out

Four mechanisms, narrowest first — [the guide](docs/secrets.md) covers which to reach for:

```php
#[\SensitiveParameter] public string $cardToken,             // never recorded at all
Context::addHidden('key', $value);                           // travels, never logged
'logs'    => ['redact' => ['keys' => ['*password*']]],       // safety net, descends into nested values
'context' => ['local_only' => ['body.*']],                   // stays in this process, never written to your queue
```

## Documentation

| | |
| --- | --- |
| [Recording context](docs/recording-context.md) | The moments, the built-in recorders, and plugging in your own |
| [Adding your own context](docs/adding-context.md) | Using Laravel's `Context` — anywhere, any time, including mid-job |
| [Registering the middleware](docs/middleware.md) | Groups, global, or a route alias — plus ordering and manual wiring |
| [Jobs](docs/jobs.md) | Child spans, `job.*` context, and opt-in job arguments |
| [Keeping secrets out](docs/secrets.md) | `#[\SensitiveParameter]`, hidden context, redaction, and your queue |
| [Handing the trace back](docs/response-headers.md) | Response headers, and showing an id to a user |
| [Interoperability](docs/interoperability.md) | The wire format, renaming keys, upstream ids, Saloon, APMs |

## Configuration at a glance

Five groups, each answering one question:

```php
'middleware'  => [...],   // where a trace begins
'record'      => [...],   // what gets recorded
'context'     => [...],   // what lands in the context
'logs'        => [...],   // what reaches a log line
'propagation' => [...],   // what leaves the application
```

`'enabled' => false` turns the lot off.

## Requirements

| | | |
| --- | --- | --- |
| PHP | 8.2+ (8.3+ on Laravel 13) | tested |
| Laravel 12 | `^12.1` | tested |
| Laravel 13 | `^13.0` | tested |
| Saloon | `^4.0`, optional | tested, and tested absent |

The floors are where the APIs this package needs first appeared, nothing more. Keeping your framework patched is your application's business and `composer audit`'s — a package that pins a security floor only goes stale on the next advisory.

### Why not Laravel 11

`Illuminate\Contracts\Log\ContextLogProcessor` arrived in **v12.1.0** — it exists in no Laravel 11 release. Without it there is no supported way to put the trace on a log record, which is most of what this package does, so Laravel 11 is not a matter of testing effort: there is nothing to hook into. That is also why the Laravel 12 floor is `^12.1` rather than `^12.0`.

Saloon v3 is unsupported for a different reason: every release carries an unpatched advisory (`<4.0.0`), so there is no safe floor to point at.

## Testing

```bash
composer test
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Credits

- [Madalin Tache](https://github.com/niladam)
- [All Contributors](../../contributors)

## License

MIT. See [LICENSE.md](LICENSE.md).
