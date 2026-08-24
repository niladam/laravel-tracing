# Handing the trace back

[← back to the README](../README.md)

The fastest way to debug a report of "it broke around 3pm" is to have the id already in the reporter's hands.

## Configuration

```php
'response' => [
    'headers' => [
        'X-Trace-Id' => 'trace_id',
    ],
],
```

A map of header name to what goes in it. Three values are understood:

| Value | Example |
| --- | --- |
| `trace_id` | `4bf92f3577b34da6a3ce929d0e0e4736` |
| `span_id` | `00f067aa0ba902b7` |
| `traceparent` | `00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01` |

Anything else is skipped rather than sent empty. Empty the array to send nothing back.

Send several if you like:

```php
'headers' => [
    'X-Trace-Id' => 'trace_id',
    'traceparent' => 'traceparent',
],
```

Returning `traceparent` is the standards-friendly option, and is what a downstream service or an instrumented browser will look for. `X-Trace-Id` is the practical one — short enough for a human to read out.

## Showing it to a user

```blade
{{-- resources/views/errors/500.blade.php --}}
<p>Something went wrong on our end.</p>
<p>Reference: <code>{{ Tracing::traceId() }}</code></p>
```

When support receives that string, `grep` it and the entire tree of work behind that request comes back — including the jobs it queued.

## A note on exposure

A trace id is an opaque random value. It reveals nothing about your infrastructure, contains no user data, and cannot be guessed backwards into anything. Returning one on a public response is normal practice.

What you should *not* do is return internal context — `company_id`, `user_id` and friends stay in your logs.
