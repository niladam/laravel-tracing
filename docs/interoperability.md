# Interoperability

[← back to the README](../README.md)

## The wire format

This package speaks [W3C Trace Context](https://www.w3.org/TR/trace-context/), the same `traceparent` header OpenTelemetry, Sentry, Datadog and Honeycomb use:

```
traceparent: 00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01
             │  └ trace-id (16 bytes)            └ parent span-id  └ flags
             └ version
```

Inbound headers are validated strictly — right shape, version not `ff`, neither id all-zero — and anything that fails is ignored in favour of a fresh trace rather than trusted.

`tracestate` is carried through untouched and kept out of your logs.

## Renaming the keys

If your log pipeline already expects particular names, rename rather than reformat:

```php
'context' => [
    'keys' => [
        'trace_id'       => 'dd.trace_id',
        'span_id'        => 'dd.span_id',
        'parent_span_id' => 'dd.parent_id',
    ],
],
```

Partial renames are fine — anything you leave out keeps its default. The facade reads through the same mapping, so `Tracing::traceId()` keeps working whatever you call it.

## Upstream request ids

Behind Cloudflare, a load balancer or an API gateway, the edge has usually already labelled the request:

```php
'propagation' => [
    'inbound_request_ids' => ['X-Request-Id', 'CF-Ray'],
],
```

The first of these that is present is recorded as `upstream_request_id`, alongside the trace. It does not *become* the trace id — an arbitrary edge id is not a valid 16-byte trace id, and pretending otherwise would produce ids that collide or fail validation downstream. Recording it means a line in Cloudflare's logs can be matched to a trace in yours.

If the edge sends a real `traceparent`, that wins and the trace simply continues; the upstream id is still recorded next to it.

## Who receives your trace

Only hosts you own:

```php
'propagation' => [
    'domains' => ['example.com'],
],
```

Empty falls back to `session.domain`. Matching is on a label boundary:

| Host | |
| --- | --- |
| `api.example.com` | traced |
| `example.com` | traced |
| `evilexample.com` | not traced |
| `example.com.evil.net` | not traced |
| `example.s3.amazonaws.com` | not traced |

Both Laravel's HTTP client and Saloon ask the same question, so the rule cannot drift between them.

## Saloon

Optional. If `saloonphp/saloon` v4 is installed, its requests are traced too — it ships its own sender, so Laravel's global HTTP middleware never sees them. If it is not installed, nothing referencing it is ever loaded.

## Moving to a real APM later

This records **correlation**, not timings — you get the tree, not a flame graph. When you add Sentry or an OpenTelemetry exporter, it reads these same ids and the traces line up. It is a feed-in, not a rewrite.
