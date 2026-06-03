<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | OpenTelemetry Integration
    |--------------------------------------------------------------------------
    |
    | When enabled, agent containers receive OTEL_EXPORTER_OTLP_ENDPOINT and
    | OTEL_SERVICE_NAME env vars so their otel-init.js bootstraps the OTel SDK
    | and ships spans to the collector on the same Docker network.
    |
    | The collector aggregates gen_ai.usage.* span attributes into metrics and
    | forwards them to POST /api/telemetry/otlp, which stores the data in the
    | agent_usage_snapshots table (source = '<provider>_otel').
    |
    */

    'enabled' => (bool) env('OTEL_ENABLED', false),

    // Endpoint the agent containers push spans to (must be reachable from the container network)
    'collector_endpoint' => env('OTEL_COLLECTOR_ENDPOINT', 'http://otel-collector:4317'),

    // Shared secret validated by the OTLP adapter endpoint.
    // Set the same value in OTEL_INTERNAL_TOKEN in both the collector config and .env
    'internal_token' => env('OTEL_INTERNAL_TOKEN', ''),
];
