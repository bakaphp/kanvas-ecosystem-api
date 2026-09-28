<?php

return [

    'dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')),

    // capture release as git sha
    // 'release' => trim(exec('git --git-dir ' . base_path('.git') . ' log --pretty="%h" -n1 HEAD')),

    // When left empty or `null` the Laravel environment will be used
    'environment' => env('SENTRY_ENVIRONMENT'),

    'breadcrumbs' => [
        // Capture Laravel logs in breadcrumbs
        'logs' => true,

        // Capture SQL queries in breadcrumbs
        'sql_queries' => true,

        // Capture bindings on SQL queries logged in breadcrumbs
        'sql_bindings' => true,

        // Capture queue job information in breadcrumbs
        'queue_info' => true,

        // Capture command information in breadcrumbs
        'command_info' => true,
    ],

    'tracing' => [
        // Trace queue jobs as their own transactions
        'queue_job_transactions' => env('SENTRY_TRACE_QUEUE_ENABLED', false),

        // Capture queue jobs as spans when executed on the sync driver
        'queue_jobs' => true,

        // Capture SQL queries as spans — disabled to cut Sentry span volume/cost.
        // SQL still appears in breadcrumbs (breadcrumbs.sql_queries) on errors;
        // this only removes per-query spans from performance traces.
        'sql_queries' => env('SENTRY_TRACE_SQL_QUERIES', false),

        // Try to find out where the SQL query originated from and add it to the query spans
        'sql_origin' => env('SENTRY_TRACE_SQL_ORIGIN', false),

        // Capture views as spans — off by default; this is an API-first app that
        // rarely renders Blade, so view spans are near-pure cost.
        'views' => env('SENTRY_TRACE_VIEWS', false),

        // Indicates if the tracing integrations supplied by Sentry should be loaded
        'default_integrations' => true,
    ],

    // @see: https://docs.sentry.io/platforms/php/configuration/options/#send-default-pii
    'send_default_pii' => env('SENTRY_SEND_DEFAULT_PII', false),

    // Fraction of requests traced for performance (0.0–1.0). Default 0.05 (5%)
    // keeps aggregate p50/p95 metrics reliable while bounding billable spans.
    // NOTE: a SENTRY_TRACES_SAMPLE_RATE set in an environment's .env OVERRIDES
    // this default — to let this value govern prod, unset it from prod's .env.
    'traces_sample_rate' => (float)(env('SENTRY_TRACES_SAMPLE_RATE', 0.05)),

    'controllers_base_namespace' => env('SENTRY_CONTROLLERS_BASE_NAMESPACE', 'App\\Http\\Controllers'),

    // Sentry Structured Logs — sends Log:: calls to Sentry's Logs product
    // (separate from Issues/Alerts, no noise). Viewable at Sentry > Logs.
    // Level filtering (warning/info/etc) is configured in config/logging.php
    // on the sentry_logs channel, not here.
    'enable_logs' => env('SENTRY_ENABLE_LOGS', false),

];
