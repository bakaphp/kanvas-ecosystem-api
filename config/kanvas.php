<?php

return [
    'application' => [ //@todo migration to app
        'routes' => [
            'prefix' => 'v1',
            'middleware' => ['api']
        ]
    ],
    'app' => [
        'id' => env('KANVAS_APP_ID'),
        'frontend_url' => env('KANVAS_FRONTEND_URL'),
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
        'max_log_batch_size' => env('MAX_LOG_BATCH_SIZE', 10)
    ],
    'puppeteer' => [
        'url' => env('PUPPETEER_API_URL', 'http://puppeteer:3000'),
        'storage_folder' => env('PUPPETEER_STORAGE_FOLDER', 'pdf'),
    ],
];
