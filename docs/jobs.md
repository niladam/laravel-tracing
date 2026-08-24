# Jobs

[← back to the README](../README.md)

## The trace follows the dispatch

You do not have to do anything for this. Laravel serialises context into the job payload; this package opens a fresh child span when the worker picks the job up:

```
Request                     trace=A  span=1  parent=null
 └─ ProcessOrder            trace=A  span=2  parent=1
     ├─ SendReceipt         trace=A  span=3  parent=2
     └─ NotifyWarehouse     trace=A  span=4  parent=2
```

Same `trace_id` throughout, a distinct `span_id` per run, and `parent_span_id` pointing at whoever dispatched it.

The child span matters more than it looks. Without it, a long-running worker stamps the span it booted with onto every unrelated job it happens to run, so one id ends up covering dozens of jobs across many classes — which looks like a trace and is not one.

## Which job was it?

```php
'context' => [
    'jobs' => ['prefix' => 'job'],
],
```

Every line logged from inside a job carries:

| Key | |
| --- | --- |
| `job.name` | `App\Jobs\ProcessOrder` |
| `job.connection` | `redis` |
| `job.queue` | `default` |
| `job.attempts` | `1` |
| `job.uuid` | the job's own id |

`job.name` is read from the command the payload wraps, not the display name — for a queued closure the display name is `Closure (file.php:12)`, which is not a class and will break anything that tries to reflect it.

These keys are **always** stripped from outgoing job payloads, so a job's children report themselves rather than inheriting their parent's details.

## Job arguments

Off by default:

```php
'context' => [
    'jobs' => ['arguments' => true],
],
```

Turned on, the job's own properties are recorded as `job.arguments.*`. Two filters apply before anything is written:

1. Constructor parameters marked `#[\SensitiveParameter]` are dropped entirely.
2. What remains passes through [`redact`](secrets.md).

```php
class ChargeCard implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $orderId,
        #[\SensitiveParameter] public string $cardToken,
    ) {}
}
```

```
job.excluded_parameters  ["cardToken"]     ← named, so you know it was withheld
job.arguments.orderId  ord-1
```

`cardToken` is absent rather than masked — it never enters the context at all.

It is off by default for two reasons: payloads can be large enough to bloat every log line a job writes, and a job is free to hold things that have no business being on disk. Turn it on for a queue you are actively debugging.

## Adding your own

Anywhere inside the job, at any point:

```php
public function handle(): void
{
    Context::add('order_id', $this->order->id);
    // …
}
```

See [Adding your own context](adding-context.md).

## Failed jobs

A failed job's exception is reported with the context intact, so your error tracker shows the same `trace_id` as the logs. Searching that id gives you the request that queued the job, everything the request did, and every sibling job it dispatched.
