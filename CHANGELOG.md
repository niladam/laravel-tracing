# Changelog

All notable changes to `laravel-tracing` will be documented in this file.

## v2.0.0 - 2026-08-24

Context is now recorded **at the moment it becomes true**, rather than snapshotted at a point in the request.

That sounds small. It means a key can correct itself — switch teams mid-request and every line after it, and every job dispatched after it, carries the new value. A middleware that snapshots once cannot do that.

### Security fix

Redaction only walked **top-level** keys, so a nested secret was as visible as its outermost key:

```php
Context::add(['body' => ['password' => 'hunter2']]);
// checked the key "body", matched nothing, written to your logs in full
```

It now descends, matching a value by its own key **or** its full dotted path. If you were relying on `redact` to catch anything nested, it wasn't. Worth a look at your logs.

### Recorders

Context comes from a list of recorder classes. Delete a line to switch one off; add your own and it registers identically:

```php
'record' => [
    RecordRequestContext::class,      // channel, ip, url, method
    RecordAuthenticatedUser::class,   // user_id, the moment any guard answers
    RecordConsoleContext::class,      // channel, command
    RecordJobContext::class,          // job.name, job.queue, job.attempts, …

    App\Tracing\RecordTenantContext::class,
],
```

A recorder names the event it waits for and returns keys to merge, so nothing depends on middleware order. For a key or two a class is too much, so there is a ladder:

```php
'context' => ['additional' => ['deployment' => env('DEPLOYMENT_ID')]],   // constant, no code

Tracing::always(fn () => ['host' => gethostname()]);
Tracing::authenticated('web', fn (User $user) => ['team_id' => $user->current_team_id]);
Tracing::on(OrderShipped::class, fn ($e) => ['order_id' => $e->order->id]);
```

### `user_id` on stateless guards

Laravel dispatches `Authenticated` from `SessionGuard` alone, so **Passport and Sanctum requests recorded no user at all**. The request's user resolver is now wrapped as a second signal, covering every driver.

### It says what it withheld

A missing value used to be indistinguishable from one that was never there. Both are now named:

```
redacted_keys            ["body.password"]     ← the ones your patterns missed are obvious beside it
job.excluded_parameters  ["cardToken"]         ← dropped via #[\SensitiveParameter]
```

Both absent when there is nothing to report, and both switchable off — that hides the name, never the withholding.

### Also

- **`channel`** — `http`, `console` or `queue`, replacing per-channel booleans
- **`logs.flatten`** — `Arr::dot` on the way to a log line, for pipelines that dislike nested arrays. Purely presentation; redaction descends on its own
- **`context.jobs.sensitive_attributes`** — have your own attribute honoured alongside `#[\SensitiveParameter]`
- **`SpanOpened`** — an event for facts with no moment of their own

### Breaking: config keys regrouped

Thirteen top-level keys became five, each answering one question. **A v1.0.0 config is silently ignored** — no error, tracing just behaves differently. Republish it:

```bash
php artisan vendor:publish --tag=laravel-tracing-config --force
```

| v1.0.0 | v2.0.0 |
| --- | --- |
| `domains` | `propagation.domains` |
| `response.headers` | `propagation.response_headers` |
| `inbound.request_id_headers` | `propagation.inbound_request_ids` |
| `merge_log_context` | `logs.merge_context` |
| `redact.*` | `logs.redact.*` |
| `keys` | `context.keys` |
| `jobs.*` | `context.jobs.*` |
| `never_queue` | `context.local_only` |

`never_queue` read as "never queue these jobs"; it keeps context out of a job payload, so it is `local_only` now.

## v1.0.0 - 2026-08-24

Initial release. W3C Trace Context for Laravel — one id follows a request through its own log lines, the queued jobs it dispatches, the jobs *those* dispatch, and the internal services it calls.

```
[13:34:12] local.INFO: order placed      {"trace_id":"d4f871…","span_id":"9215fefcbdd8b18e","parent_span_id":null}
[13:34:13] local.INFO: charging card     {"trace_id":"d4f871…","span_id":"16d7e9be2ca54e25","parent_span_id":"9215fefcbdd8b18e"}
[13:34:13] local.INFO: sending receipt   {"trace_id":"d4f871…","span_id":"5b18f3563067f4f8","parent_span_id":"16d7e9be2ca54e25"}
```

`grep` one `trace_id` for the whole tree; `parent_span_id` tells you who triggered what.

### What it does

- **A trace id minted once at the origin**, never regenerated as it travels. Without that, a long-running worker stamps the span it booted with on every unrelated job it happens to run — one id covering dozens of jobs, which looks like a trace and is not one.
- **Inbound `traceparent` / `tracestate`** read and continued, validated strictly: a malformed header starts a fresh trace rather than being trusted.
- **Queued jobs open a child span**, so a job reports its own span and points back at whoever dispatched it.
- **Outgoing `traceparent` on your own hosts only**, matched on a label boundary so a look-alike domain or a bucket named after you is treated as somebody else's. Covers Laravel's HTTP client and Saloon, which ships its own sender.
- **One log line, not two.** Laravel writes ambient context to a record's `extra`, which `LineFormatter` renders as a second JSON blob; this merges both into `context`.
- **`redact`** masks context whose key looks sensitive before it reaches a log line.
- **`#[\SensitiveParameter]`** on a job's constructor is honoured, so a job that has already declared its secrets need not declare them twice.
- **`never_queue`** keeps chosen context out of job payloads, so request details you want in logs are not also written to your queue.
- **Response headers** hand the trace id back to the caller, so support can quote a reference instead of hunting for it.
- **Upstream request ids** — the first of `X-Request-Id` / `CF-Ray` present is recorded alongside the trace, so a line in your edge's logs matches a trace in yours.
- **Renameable context keys**, to drop into a pipeline that already expects particular names.
