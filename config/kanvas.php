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
        'max_attempts' => env('API_RATE_LIMIT_MAX_ATTEMPTS', 120),
        'decay_minutes' => env('API_RATE_LIMIT_DECAY_MINUTES', 1),
    ],
];
