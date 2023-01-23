<?php

return [
    'application' => [ //@todo migration to app
        'production' => env('APP_ENV', 'development'),
        'development' => env('DEVELOPMENT'),
        'jwtSecurity' => env('JWT_SECURITY'),
        'debug' => [
            'profile' => env('DEBUG_PROFILE'),
            'logQueries' => env('DEBUG_QUERY'),
            'logRequest' => env('DEBUG_REQUEST')
        ],
        'routes' => [
            'prefix' => 'v1',
            'middleware' => ['api']
        ]
    ],
    'app' => [
        'id' => env('KANVAS_APP_ID'),
        'frontEndUrl' => env('FRONTEND_URL'),
        'version' => env('VERSION', time()),
        'timezone' => 'UTC',
        'debug' => env('APP_DEBUG', false),
        'env' => env('APP_ENV', 'development'),
        'production' => env('APP_ENV', 'development'),
        'logsReport' => env('APP_LOGS_REPORT', false),
        'devMode' => boolval(
            'development' === env('APP_ENV', 'development')
        ),
        'viewsDir' => app_path('storage/view/'),
        'baseUri' => env('APP_BASE_URI'),
        'supportEmail' => env('APP_SUPPORT_EMAIL'),
        'time' => microtime(true),
        'namespaceName' => env('APP_NAMESPACE'),
        'subscription' => [
            'defaultPlan' => [
                'name' => 'default-free-trial'
            ]
        ]
    ],
    'filesystem' => [
        //temp directory where we will upload our files before moving them to the final location
        'uploadDirectory' => app_path(env('LOCAL_UPLOAD_DIR_TEMP')),
        'local' => [
            'path' => app_path(env('LOCAL_UPLOAD_DIR')),
            'cdn' => env('FILESYSTEM_CDN_URL'),
        ],
        's3' => [
            'info' => [
                'credentials' => [
                    'key' => env('S3_PUBLIC_KEY'),
                    'secret' => env('S3_SECRET_KEY'),
                ],
                'region' => env('S3_REGION'),
                'version' => env('S3_VERSION'),
            ],
            'path' => env('S3_UPLOAD_DIR'),
            'bucket' => env('S3_BUCKET'),
            'cdn' => env('S3_CDN_URL'),
        ],
    ],
    'cache' => [
        'adapter' => 'redis',
        'options' => [
            'redis' => [
                'defaultSerializer' => Redis::SERIALIZER_PHP,
                'host' => env('REDIS_HOST', '127.0.0.1'),
                'port' => env('REDIS_PORT', 6379),
                'lifetime' => env('CACHE_LIFETIME', 86400),
                'index' => 1,
                'prefix' => 'data-',
            ],
        ],
        'metadata' => [
            'dev' => [
                'adapter' => 'Memory',
                'options' => [],
            ],
            'prod' => [
                'adapter' => 'redis',
                'options' => [
                    'host' => env('REDIS_HOST', '127.0.0.1'),
                    'port' => env('REDIS_PORT', 6379),
                    'lifetime' => env('CACHE_LIFETIME', 86400),
                    'prefix' => 'metadatas-caches-'
                ],
            ],
        ],
    ],
    'email' => [
        'driver' => 'smtp',
        'host' => env('EMAIL_HOST'),
        'port' => env('EMAIL_PORT'),
        'username' => env('EMAIL_USER'),
        'password' => env('EMAIL_PASS'),
        'from' => [
            'email' => env('EMAIL_FROM_PRODUCTION'),
            'name' => env('EMAIL_FROM_NAME_PRODUCTION'),
        ],
        'debug' => [
            'from' => [
                'email' => env('EMAIL_FROM_DEBUG'),
                'name' => env('EMAIL_FROM_NAME_DEBUG'),
            ],
        ],
    ],
    'elasticSearch' => [
        'hosts' => [env('ELASTIC_HOST')], //change to pass array
    ],
    'jwt' => [
        'secretKey' => env('APP_JWT_TOKEN'),
        'payload' => [
            'exp' => env('APP_JWT_SESSION_EXPIRATION', 1440),
            'refresh_exp' => env('APP_JWT_REFRESH_EXPIRATION', 3440),
            'iss' => 'phalcon-jwt-auth',
        ],
    ],
    'pusher' => [
        'id' => env('PUSHER_ID'),
        'key' => env('PUSHER_KEY'),
        'secret' => env('PUSHER_SECRET'),
        'cluster' => env('PUSHER_CLUSTER'),
        'queue' => env('PUSHER_QUEUE')
    ],
    'stripe' => [
        'secretKey' => env('STRIPE_SECRET'),
        'secret' => env('STRIPE_SECRET'),
        'public' => env('STRIPE_PUBLIC'),
    ],
    'pushNotifications' => [
        'appId' => env('CANVAS_ONESIGNAL_APP_ID'),
        'authKey' => env('CANVAS_ONESIGNAL_AUTH_KEY'),
        'userAuthKey' => env('CANVAS_ONESIGNAL_APP_USER_AUTH_KEY')
    ]
];
