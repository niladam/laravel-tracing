# Adding your own context

[← back to the README](../README.md)

**Use Laravel's `Context`.** This package does not wrap it, shadow it or replace it — it traces whatever is already in it, so [the framework documentation](https://laravel.com/docs/context) is the documentation for this too.

```php
use Illuminate\Support\Facades\Context;

Context::add(['company_id' => $company->id, 'plan' => $company->plan]);
Context::add('order_id', $order->id);
```

Everything you add is written to every log line for the rest of the current request, job or command, **and** carried into the jobs that unit dispatches.

## Anywhere, at any time

There is no registration step and no builder to hook into. Call it wherever you happen to be — a controller, a service, an event listener, halfway through a job that has already added context of its own:

```php
public function handle(): void
{
    Context::add('invoice_id', $this->invoice->id);

    foreach ($this->invoice->lines as $line) {
        Context::add('line_id', $line->id);   // overwrites the previous value

        $this->process($line);                // every log line in here carries line_id
    }
}
```

Adding the same key again replaces it, so the loop above labels each line's work rather than accumulating.

## How long it lasts

| Where you add it | How far it reaches |
| --- | --- |
| Middleware / controller | The rest of the request, plus every job dispatched during it |
| Inside a job | The rest of that job, plus every job *it* dispatches |
| A console command | The rest of the command, plus jobs it dispatches and subprocesses it spawns |

It never leaks sideways: two jobs running in the same worker cannot see each other's context, because the worker replaces it wholesale for each job.

## Values that must not be logged

`Context::addHidden()` travels with the trace exactly like `add()`, but is never written to a log line:

```php
Context::addHidden('idempotency_key', $key);
```

Use it for anything a downstream job needs but a log file should not hold. See [Keeping secrets out](secrets.md) for the other mechanisms.

Note that `Context::forget()` clears only the visible store. To remove a hidden value you need `Context::forgetHidden()` as well — that is the framework's behaviour, and this package does not change it.

## Reading the trace

This is the one thing `Context` cannot answer on its own, because the keys are configurable:

```php
use Niladam\LaravelTracing\Facades\Tracing;

Tracing::traceId();        // 4bf92f3577b34da6a3ce929d0e0e4736
Tracing::spanId();         // 00f067aa0ba902b7
Tracing::parentSpanId();   // null at the root
Tracing::traceparent();    // 00-4bf92f35…-00f067aa0ba902b7-01
Tracing::trace();          // the TraceContext value object, or null
```

`Context::get('trace_id')` works too, and is perfectly fine if you have not renamed anything — but it returns `null` the moment someone sets [`keys`](interoperability.md#renaming-the-keys), whereas the facade reads through that mapping.

`traceparent()` is what you hand to a client this package does not propagate for:

```php
$soapClient->addHeader('traceparent', Tracing::traceparent());
```

## Starting a fresh trace on purpose

A long-running process handling unrelated units of work — a custom consumer, say — can cut a new trace per unit:

```php
foreach ($this->messages() as $message) {
    Tracing::startNewTrace();
    Context::add('message_id', $message->id);

    $this->handle($message);
}
```

Without it every message would share the trace the process booted with, which is the same problem a queue worker would have if the package did not already open a fresh span per job.
