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

## Why

Laravel's `Context` is already carried into queued jobs, but it lands in a log record's `extra` — a *second* JSON blob on every line — and a correlation id minted once per process gets stamped on every unrelated job a worker happens to run. This package fixes both, and adds the pieces `Context` does not cover: reading and sending the standard header, and keeping secrets out of your logs and your queue.

## Installation

```bash
composer require niladam/laravel-tracing
```

The service provider is auto-discovered. Publish the config if you want to change anything:

```bash
php artisan vendor:publish --tag=laravel-tracing-config
```

That is the whole setup. Requests are traced, jobs inherit the trace, and log lines carry it.

## What you get

| Key | Meaning |
| --- | --- |
| `trace_id` | 32 hex. The root. Minted once at the origin, **never** regenerated as it travels. |
| `span_id` | 16 hex. This one unit of work — this request, this job run, this command. |
| `parent_span_id` | The `span_id` that caused this one. `null` at the root. |

Two more travel with the trace but stay out of your logs: `trace_flags` and `trace_state`.

## Boundaries it crosses

| Boundary | How |
| --- | --- |
| Inbound HTTP | Reads `traceparent` / `tracestate` and continues the caller's trace |
| Queued jobs | Laravel's context dehydrate/hydrate, with a fresh child span per job run |
| Console subprocesses | Laravel rehydrates `__LARAVEL_CONTEXT`, so `Artisan::call` children join in |
| Outgoing HTTP | `traceparent` injected on your own hosts only |
| Saloon | Registered separately, since Saloon ships its own sender. Optional. |
| Logs | Merged into each record's `context` |

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

## The short version

**Attach your own data** with Laravel's own `Context` — there is nothing new to learn, and no second store. Anything in it is traced, logged and carried into jobs:

```php
use Illuminate\Support\Facades\Context;

Context::add(['company_id' => $company->id, 'order_id' => $order->id]);
Context::addHidden('idempotency_key', $key);   // travels to jobs, never logged
```

**Read the trace** through the facade, which knows what you have named the keys:

```php
use Niladam\LaravelTracing\Facades\Tracing;

Tracing::traceId();       // 4bf92f3577b34da6a3ce929d0e0e4736
Tracing::traceparent();   // to hand to a client this package does not cover
```

**Know which job a line came from.** Every line logged inside a job carries `job.name`, `job.connection`, `job.queue`, `job.attempts` and `job.uuid`. These never reach the payload of a job it dispatches, so children report themselves.

**Keep secrets out**, three ways — see [the guide](docs/secrets.md) for which to reach for:

```php
#[\SensitiveParameter] public string $cardToken,   // never recorded at all
Context::addHidden('key', $value);                 // travels, never logged
'logs' => ['redact' => ['keys' => ['*password*']]],  // safety net for everything else
'context' => ['local_only' => ['body.*']],         // keeps bulk out of Redis
```

**Hand the id back to the caller**, so support can quote it instead of hunting for it:

```php
'propagation' => ['response_headers' => ['X-Trace-Id' => 'trace_id']],
```

**Only your own hosts get your trace.** `domains` defaults to `session.domain`, and matching is on a label boundary — `evilexample.com` and `example.s3.amazonaws.com` are somebody else's.

**Flatten nested context** for log pipelines that dislike arrays (New Relic among them) with `flatten_context` — logs only, so job payloads keep their real structure. Purely cosmetic: redaction descends into nested values either way.

**One log line, not two.** Laravel writes ambient context to a record's `extra`, which `LineFormatter` renders as a *second* JSON blob. This merges both into `context` and leaves `extra` empty — set `merge_log_context` to `false` for Laravel's default split.

**Register the middleware however suits you** — prepended to groups, onto the global stack, or as a route alias for one-off routes:

```php
'middleware' => [
    'groups' => ['web', 'api'],
    'global' => false,
    'alias'  => 'trace',      // Route::middleware('trace')->...
],
```

**Turn it all off** with `enabled => false`.

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
