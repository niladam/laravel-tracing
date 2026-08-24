<?php

declare(strict_types=1);

use Niladam\LaravelTracing\Recorders\RecordAuthenticatedUser;
use Niladam\LaravelTracing\Recorders\RecordConsoleContext;
use Niladam\LaravelTracing\Recorders\RecordJobContext;
use Niladam\LaravelTracing\Recorders\RecordRequestContext;

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Turn tracing off entirely. Nothing is registered, no headers are read or
    | sent, and log lines go back to whatever they looked like before.
    |
    */

    'enabled' => (bool) env('TRACING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Where a trace begins
    |--------------------------------------------------------------------------
    |
    | An entry point without the middleware silently starts a fresh trace, so
    | TraceRequests is registered for you. Three ways, use any combination:
    |
    |   groups — prepended to each of these middleware groups, ahead of
    |            everything else in them. Groups you have not defined are
    |            skipped, so listing one is harmless.
    |
    |   global — prepended to the global stack, so routes belonging to no
    |            group at all are traced too.
    |
    |   alias  — registers a route alias, so ->middleware('trace') works on
    |            an individual route or controller. null to skip.
    |
    | Empty them all to wire it up yourself.
    |
    */

    'middleware' => [
        'groups' => ['web', 'api'],

        'global' => (bool) env('TRACING_GLOBAL_MIDDLEWARE', false),

        'alias' => 'trace',
    ],

    /*
    |--------------------------------------------------------------------------
    | What gets recorded
    |--------------------------------------------------------------------------
    |
    | Each recorder names the event it waits for and returns the keys to merge,
    | so it writes at the moment its facts become true and nothing depends on
    | middleware ordering.
    |
    | Delete a line to switch one off. Add your own — implement the Recorder
    | contract — and it is registered exactly like the built-ins:
    |
    |     App\Tracing\RecordTenantContext::class,
    |
    | For a key or two, a class is more than you need: reach for
    | Tracing::always(), Tracing::on() or Tracing::authenticated() instead.
    |
    | What the built-ins record:
    |
    |   RecordRequestContext        channel, ip, url, method
    |   RecordAuthenticatedUser     user_id, the moment any guard answers —
    |                               session and stateless alike, so Passport
    |                               and Sanctum are covered too
    |   RecordConsoleContext        channel, command
    |   RecordJobContext            job.name, job.connection, job.queue,
    |                               job.attempts, job.uuid
    |
    */

    'record' => [
        RecordRequestContext::class,
        RecordAuthenticatedUser::class,
        RecordConsoleContext::class,
        RecordJobContext::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | What lands in the context
    |--------------------------------------------------------------------------
    |
    | Context is what every log line carries, and what travels with a job you
    | dispatch. This is what the package puts there.
    |
    */

    'context' => [

        /*
         * Static keys attached to every request, job and command.
         *
         * Must be serialisable, so config:cache keeps working — anything that
         * has to be worked out at runtime belongs on a recorder instead.
         */
        'additional' => [
            // 'deployment' => env('DEPLOYMENT_ID'),
        ],

        /*
         * Context keys that stay in this process.
         *
         * Everything in the context is written into the payload of every job
         * you dispatch, which means it lands in your queue and sits there.
         * Keys matching these patterns are left out of that payload — the
         * running process keeps them, so your request logs are unaffected.
         *
         *     'local_only' => ['body.*', 'query.*'],
         *
         * Same pattern syntax as logs.redact. Empty means everything travels.
         */
        'local_only' => [],

        /*
         * Record the request body and query string, as body.* and query.* keys.
         *
         * Off by default: a payload can be large enough to bloat every line the
         * request writes, and is the likeliest place for something you would
         * rather not keep.
         */
        'request_payload' => (bool) env('TRACING_RECORD_REQUEST_PAYLOAD', false),

        /*
         * Rename the keys the trace is stored under, to drop into a pipeline
         * that already expects particular names — Datadog reads "dd.trace_id".
         *
         * Partial renames are fine; anything omitted keeps its default.
         */
        'keys' => [
            'trace_id' => 'trace_id',
            'span_id' => 'span_id',
            'parent_span_id' => 'parent_span_id',
            'trace_flags' => 'trace_flags',
            'trace_state' => 'trace_state',
            'upstream_request_id' => 'upstream_request_id',
        ],

        'jobs' => [

            /*
             * The prefix job details are recorded under. These keys are always
             * left out of a job payload, so a job's children report themselves
             * rather than inheriting whatever dispatched them.
             */
            'prefix' => 'job',

            /*
             * Also record the job's own properties, as job.arguments.* keys.
             *
             * Off by default: a job is free to hold things that have no
             * business being written to a log file. Parameters marked by one
             * of "sensitive_attributes" are dropped outright, and whatever
             * remains still passes through logs.redact.
             */
            'arguments' => (bool) env('TRACING_JOB_ARGUMENTS', false),

            /*
             * Name the parameters that were withheld, as job.excluded_parameters,
             * so a missing argument is not confused with one that was never set.
             *
             * A name is not a value, but it still says the job holds a
             * "cardToken" — switch it off if even that is more than you want
             * written down.
             */
            'name_excluded_parameters' => (bool) env('TRACING_JOB_NAME_EXCLUDED', true),

            /*
             * Attributes marking a constructor parameter as sensitive.
             *
             * Job arguments only, since that is the only place this package
             * reflects over parameters. PHP's own #[\SensitiveParameter] is
             * honoured out of the box; add your own if you have one.
             */
            'sensitive_attributes' => [
                SensitiveParameter::class,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | What reaches a log line
    |--------------------------------------------------------------------------
    |
    | merge_context  Laravel writes the ambient context to a record's "extra",
    |                which Monolog's LineFormatter renders as a second JSON
    |                blob after "%context%". Merging keeps every line to a
    |                single object.
    |
    | flatten        Runs the context through Arr::dot on its way to a log
    |                line, so ['body' => ['address' => '…']] is written as
    |                "body.address". For pipelines that handle nested arrays
    |                badly — New Relic is the usual case. Logs only: job
    |                payloads keep their real structure. Purely presentation;
    |                redaction descends into nested values on its own.
    |
    | redact         Context whose key matches one of these is masked before it
    |                reaches a log line — every log line, from any source.
    |                Patterns are case-insensitive, "*" meaning any run of
    |                characters, so "*password*" covers "password_confirmation".
    |                Nested values are descended into, and a value matches on
    |                its own key or its full dotted path, so "body.address"
    |                targets exactly one field.
    |
    |                A safety net for context added elsewhere. For a value that
    |                should travel but never be written down, prefer
    |                Context::addHidden(), which keeps it out of logs entirely.
    |
    */

    'logs' => [
        'merge_context' => (bool) env('TRACING_MERGE_LOG_CONTEXT', true),

        'flatten' => (bool) env('TRACING_FLATTEN_CONTEXT', false),

        'redact' => [
            'keys' => [
                '*password*',
                '*secret*',
                '*token*',
                '*authorization*',
                '*api_key*',
                '*apikey*',
                '*credit_card*',
                '*cvv*',
            ],

            'replacement' => '[redacted]',

            /*
             * Name what was masked, as a "redacted_keys" list on the line.
             *
             * Scanning for "[redacted]" tells you what was caught; the list
             * tells you the same at a glance, and makes the keys your patterns
             * are missing obvious right next to it. Absent when nothing was
             * masked. A key name is not a value, but it does say the field
             * exists — switch it off if that is more than you want written.
             */
            'name_redacted_keys' => (bool) env('TRACING_NAME_REDACTED_KEYS', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | What leaves the application
    |--------------------------------------------------------------------------
    |
    | domains             Outgoing requests only carry the `traceparent` header
    |                     when their host is one of these, or a subdomain of
    |                     one. Everything else is a third party and is told
    |                     nothing. Leave empty to fall back to session.domain,
    |                     already the boundary every subdomain shares.
    |
    | response_headers    Hands the trace back to the caller, so an id is
    |                     visible without reading logs — put it on your error
    |                     page and support can quote it. Maps a header name to
    |                     what goes in it: "trace_id", "span_id" or
    |                     "traceparent". Empty to send nothing back.
    |
    | inbound_request_ids A proxy or CDN in front of the application often
    |                     stamps its own id. The first of these present is
    |                     recorded alongside the trace, so a line in the edge's
    |                     logs can be matched to a trace in yours.
    |
    */

    'propagation' => [
        'domains' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('TRACING_DOMAINS', '')))
        )),

        'response_headers' => [
            'X-Trace-Id' => 'trace_id',
        ],

        'inbound_request_ids' => [
            'X-Request-Id',
            'CF-Ray',
        ],
    ],

];
