# Keeping secrets out

[← back to the README](../README.md)

Context reaches two places you should think about before putting something in it: **log files**, and **the payload of every job you dispatch** — which means Redis or your database, at rest, until that job is pruned.

Three mechanisms, in order of how specific they are.

## 1. `#[\SensitiveParameter]` — the job declares its own

PHP already has an attribute for "this value must not be written down" — it keeps a parameter out of stack traces. This package honours the same attribute, so a job that has declared its secrets does not have to declare them twice:

```php
use SensitiveParameter;

class ChargeCard implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $invoiceId,
        #[SensitiveParameter] public string $cardToken,
    ) {}
}
```

With `jobs.arguments` enabled, `invoiceId` is recorded and `cardToken` is not — it never enters the context, so it cannot reach a log line or be redacted-but-present.

This only applies to constructor parameters of a queued job, and only when `jobs.arguments` is on (it is off by default).

## 2. `Context::addHidden()` — one value, travels but is never logged

```php
Context::addHidden('idempotency_key', $key);
```

The value is carried into the jobs you dispatch and is readable there with `Context::getHidden()`, but no log line will ever contain it. This is the right tool when a downstream job genuinely needs the value.

## 3. `redact` — a safety net for everything else

Context added elsewhere in your application — by a request-enrichment middleware, a package, a colleague — is masked on the way to a log line when its key matches a pattern:

```php
'redact' => [
    'keys' => ['*password*', '*secret*', '*token*', '*authorization*', '*api_key*'],
    'replacement' => '[redacted]',
],
```

Patterns are case-insensitive; `*` matches any run of characters, so `*password*` also covers `body.password_confirmation` and `PASSWORD`.

Nested values are a blind spot here: `['body' => ['password' => '…']]` is checked as the key `body`, which matches nothing, so the secret is written out in full. Turning on [`flatten_context`](#flattening-nested-context) makes it `body.password` before redaction runs, and the pattern then catches it.

This masks the value **in logs only**. The real value is still in the running process and, unless you also use `never_queue`, still written to job payloads. Redaction is a backstop, not a substitute for not adding the value.

## Keeping things out of your queue

Separate setting, separate problem. Context is serialised into the payload of every job you dispatch:

```php
'never_queue' => ['body.*', 'query.*'],
```

Matching keys are stripped from the payload on the way in. The running process keeps them — your request logs still show the request body; your queue simply never receives it.

Job details recorded by this package (`job.*`) are always stripped this way, so a job's children carry their own details rather than inheriting whatever dispatched them.

## Flattening nested context

```php
'flatten_context' => true,
```

Runs the context through `Arr::dot` on its way to a log line, so `['body' => ['address' => '…']]` is written as `body.address`. Off by default.

Two reasons to turn it on:

1. **Your log pipeline dislikes nested arrays.** New Relic is the usual case.
2. **Redaction reaches deeper**, as above — flattening happens *before* redacting, never after.

It applies to logs only. Job payloads keep their real structure, so a nested value still arrives intact on the other side of a queue, and `Context::get()` returns what you put in.

Empty arrays are left as-is, which is `Arr::dot`'s own behaviour — `['body' => []]` stays `body => []`.

## Choosing between them

| You want to… | Use |
| --- | --- |
| Stop a job's constructor argument being recorded at all | `#[\SensitiveParameter]` |
| Pass a secret to a downstream job without logging it | `Context::addHidden()` |
| Catch sensitive keys added anywhere in the app | `redact.keys` |
| Keep bulky or private context out of Redis | `never_queue` |
| Let redaction see inside nested arrays | `flatten_context` |
