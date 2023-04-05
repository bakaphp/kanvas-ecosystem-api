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
    ],
    'jwt' => [
        'secretKey' => env('APP_JWT_TOKEN'),
        'payload' => [
            'exp' => env('APP_JWT_SESSION_EXPIRATION', 2628000),
            'refresh_exp' => env('APP_JWT_REFRESH_EXPIRATION', 3028000),
            'iss' => 'phalcon-jwt-auth',
        ],
    ],
];
