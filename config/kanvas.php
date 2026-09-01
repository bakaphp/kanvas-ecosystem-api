<?php

return [
    'application' => [ //@todo migration to app
        'routes' => [
            'prefix' => 'v1',
            'middleware' => ['api'],
        ],
    ],
    'app' => [
        'id' => env('KANVAS_APP_ID'),
        'frontend_url' => env('KANVAS_FRONTEND_URL'),
        'google' => [
            'google_play_credentials_json' => env('GOOGLE_PLAY_CREDENTIALS'),
        ],
    ],
    'jwt' => [
        'secretKey' => env('APP_JWT_TOKEN'),
        'payload' => [
            'exp' => env('APP_JWT_SESSION_EXPIRATION', 2628000),
            'refresh_exp' => env('APP_JWT_REFRESH_EXPIRATION', 3028000),
            'iss' => 'phalcon-jwt-auth',
        ],
    ],
    'logger' => [
        'max_log_batch_size' => env('MAX_LOG_BATCH_SIZE', 10),
    ],
    'puppeteer' => [
        'url' => env('PUPPETEER_API_URL', 'http://puppeteer:3000'),
        'storage_folder' => env('PUPPETEER_STORAGE_FOLDER', 'pdf'),
    ],
    'ipinfo' => [
        'token' => env('IPINFO_API_KEY'),
    ],
    'ratelimit' => [
        'enabled' => env('API_RATE_LIMIT_ENABLED', true),
        'max_attempts' => env('API_RATE_LIMIT_MAX_ATTEMPTS', 250),
        'decay_minutes' => env('API_RATE_LIMIT_DECAY_MINUTES', 1),
    ],
    'signup_anomaly' => [
        'alert_emails' => env('SIGNUP_ANOMALY_ALERT_EMAILS'),
        'sentry_enabled' => env('SIGNUP_ABUSE_SENTRY_ENABLED', true),
    ],
    // External voice runtime (Pipecat / Cloud Run). A single deployment serves
    // every app, so these are the global default; a per-app setting
    // (kanvas-intelligence-voice-runtime-*) still overrides when present.
    'voice_runtime' => [
        'url' => env('VOICE_RUNTIME_URL'),
        'api_token' => env('VOICE_RUNTIME_API_TOKEN'),
        // Global default for cross-app voice-agent resolution when an app has no
        // per-app VOICE_RUNTIME_CROSS_APP setting. Set true to let the shared
        // single runtime resolve agents in every app via one env var. SECURITY:
        // this makes every app-key a cross-tenant reader — leave false for
        // multi-key/tenant-scoped deployments.
        'cross_app' => env('VOICE_RUNTIME_CROSS_APP', false),
    ],
];
