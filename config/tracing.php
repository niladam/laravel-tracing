<?php

declare(strict_types=1);

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
    | Middleware
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
    | Propagated domains
    |--------------------------------------------------------------------------
    |
    | Outgoing requests only carry the `traceparent` header when their host is
    | one of these domains, or a subdomain of one. Everything else is a third
    | party and is told nothing about your traces.
    |
    | Leave empty to fall back to `session.domain`, which is already the
    | boundary every subdomain of the application shares.
    |
    */

    'domains' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('TRACING_DOMAINS', '')))
    )),

    /*
    |--------------------------------------------------------------------------
    | Log context
    |--------------------------------------------------------------------------
    |
    | Laravel writes the ambient context to a log record's "extra", which
    | Monolog's LineFormatter renders as a second JSON blob after "%context%".
    | Merging instead keeps every line to a single object.
    |
    */

    'merge_log_context' => (bool) env('TRACING_MERGE_LOG_CONTEXT', true),

    /*
    |--------------------------------------------------------------------------
    | Redacted keys
    |--------------------------------------------------------------------------
    |
    | Context whose key matches one of these is masked before it reaches a log
    | line. Patterns are matched case-insensitively, `*` meaning any run of
    | characters, so "*password*" also covers "body.password_confirmation".
    |
    | This is a safety net for context added elsewhere. For a value that should
    | travel with the trace but never be written down, prefer
    | Tracing::addHidden(), which keeps it out of logs entirely.
    |
    */

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
    ],

    /*
    |--------------------------------------------------------------------------
    | Job context
    |--------------------------------------------------------------------------
    |
    | Records which job a line was logged from — its name, connection, queue,
    | attempt and uuid — under the given prefix. These keys never reach a job
    | payload, so a job's children carry their own details, not its.
    |
    */

    'jobs' => [
        'enabled' => (bool) env('TRACING_JOB_CONTEXT', true),

        'prefix' => 'job',

        /*
         * Also record the job's own properties. Off by default: a payload can
         * be large, and a job is free to hold things that have no business
         * being written to a log file.
         *
         * Constructor parameters marked #[\SensitiveParameter] are dropped,
         * and whatever remains still passes through "redact" below.
         */
        'arguments' => (bool) env('TRACING_JOB_ARGUMENTS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Response headers
    |--------------------------------------------------------------------------
    |
    | Hands the trace back to the caller, so an id is visible without reading
    | logs — put it on your error page and support can quote it back to you.
    |
    | Maps a header name to what goes in it: "trace_id", "span_id" or
    | "traceparent". Empty the array to send nothing back.
    |
    */

    'response' => [
        'headers' => [
            'X-Trace-Id' => 'trace_id',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound request ids
    |--------------------------------------------------------------------------
    |
    | A proxy or CDN in front of the application often stamps its own id. When
    | a request arrives without a `traceparent`, the first of these headers
    | that is present is recorded alongside the trace, so a line in the edge's
    | logs can be matched to a trace in yours.
    |
    */

    'inbound' => [
        'request_id_headers' => [
            'X-Request-Id',
            'CF-Ray',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Context keys
    |--------------------------------------------------------------------------
    |
    | Rename the keys the trace is stored under, to drop straight into a log
    | pipeline that already expects particular names — Datadog reads
    | "dd.trace_id", for instance — rather than asking it to change.
    |
    */

    'keys' => [
        'trace_id' => 'trace_id',
        'span_id' => 'span_id',
        'parent_span_id' => 'parent_span_id',
        'trace_flags' => 'trace_flags',
        'trace_state' => 'trace_state',
        'upstream_request_id' => 'upstream_request_id',
    ],

    /*
    |--------------------------------------------------------------------------
    | Kept out of queued jobs
    |--------------------------------------------------------------------------
    |
    | Context is serialised into the payload of every job you dispatch. Keys
    | matching these patterns are stripped on the way in, so request details
    | you want in logs do not also get written to your queue.
    |
    | Same pattern syntax as "redact". Empty means everything travels.
    |
    */

    'never_queue' => [],

];
