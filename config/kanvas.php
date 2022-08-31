<?php

return [
    'application' => [ //@todo migration to app
        'production' => getenv('APP_ENV', 'development'),
        'development' => getenv('DEVELOPMENT'),
        'jwtSecurity' => getenv('JWT_SECURITY'),
        'debug' => [
            'profile' => getenv('DEBUG_PROFILE'),
            'logQueries' => getenv('DEBUG_QUERY'),
            'logRequest' => getenv('DEBUG_REQUEST')
        ],
        'routes' => [
            'prefix' => 'v1',
            'middleware' => ['api']
        ]
    ],
    'app' => [
        'id' => getenv('KANVAS_APP_ID'),
        'frontEndUrl' => getenv('FRONTEND_URL'),
        'version' => getenv('VERSION', time()),
        'timezone' => "UTC",
        'debug' => getenv('APP_DEBUG', false),
        'env' => getenv('APP_ENV', 'development'),
        'production' => getenv('APP_ENV', 'development'),
        'logsReport' => getenv('APP_LOGS_REPORT', false),
        'devMode' => boolval(
            'development' === getenv('APP_ENV', 'development')
        ),
        'viewsDir' => app_path('storage/view/'),
        'baseUri' => getenv('APP_BASE_URI'),
        'supportEmail' => getenv('APP_SUPPORT_EMAIL'),
        'time' => microtime(true),
        'namespaceName' => getenv('APP_NAMESPACE'),
        'subscription' => [
            'defaultPlan' => [
                'name' => 'default-free-trial'
            ]
        ]
    ],
    'filesystem' => [
        //temp directory where we will upload our files before moving them to the final location
        'uploadDirectory' => app_path(getenv('LOCAL_UPLOAD_DIR_TEMP')),
        'local' => [
            'path' => app_path(getenv('LOCAL_UPLOAD_DIR')),
            'cdn' => getenv('FILESYSTEM_CDN_URL'),
        ],
        's3' => [
            'info' => [
                'credentials' => [
                    'key' => getenv('S3_PUBLIC_KEY'),
                    'secret' => getenv('S3_SECRET_KEY'),
                ],
                'region' => getenv('S3_REGION'),
                'version' => getenv('S3_VERSION'),
            ],
            'path' => getenv('S3_UPLOAD_DIR'),
            'bucket' => getenv('S3_BUCKET'),
            'cdn' => getenv('S3_CDN_URL'),
        ],
    ],
    'cache' => [
        'adapter' => 'redis',
        'options' => [
            'redis' => [
                'defaultSerializer' => Redis::SERIALIZER_PHP,
                'host' => getenv('REDIS_HOST', '127.0.0.1'),
                'port' => getenv('REDIS_PORT', 6379),
                'lifetime' => getenv('CACHE_LIFETIME', 86400),
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
                    'host' => getenv('REDIS_HOST', '127.0.0.1'),
                    'port' => getenv('REDIS_PORT', 6379),
                    'lifetime' => getenv('CACHE_LIFETIME', 86400),
                    'prefix' => 'metadatas-caches-'
                ],
            ],
        ],
    ],
    'email' => [
        'driver' => 'smtp',
        'host' => getenv('EMAIL_HOST'),
        'port' => getenv('EMAIL_PORT'),
        'username' => getenv('EMAIL_USER'),
        'password' => getenv('EMAIL_PASS'),
        'from' => [
            'email' => getenv('EMAIL_FROM_PRODUCTION'),
            'name' => getenv('EMAIL_FROM_NAME_PRODUCTION'),
        ],
        'debug' => [
            'from' => [
                'email' => getenv('EMAIL_FROM_DEBUG'),
                'name' => getenv('EMAIL_FROM_NAME_DEBUG'),
            ],
        ],
    ],
    'elasticSearch' => [
        'hosts' => [getenv('ELASTIC_HOST')], //change to pass array
    ],
    'jwt' => [
        'secretKey' => getenv('APP_JWT_TOKEN'),
        'payload' => [
            'exp' => getenv('APP_JWT_SESSION_EXPIRATION', 1440),
            'refresh_exp' => getenv('APP_JWT_REFRESH_EXPIRATION', 3440),
            'iss' => 'phalcon-jwt-auth',
        ],
    ],
    'pusher' => [
        'id' => getenv('PUSHER_ID'),
        'key' => getenv('PUSHER_KEY'),
        'secret' => getenv('PUSHER_SECRET'),
        'cluster' => getenv('PUSHER_CLUSTER'),
        'queue' => getenv('PUSHER_QUEUE')
    ],
    'stripe' => [
        'secretKey' => getenv('STRIPE_SECRET'),
        'secret' => getenv('STRIPE_SECRET'),
        'public' => getenv('STRIPE_PUBLIC'),
    ],
    'pushNotifications' => [
        'appId' => getenv('CANVAS_ONESIGNAL_APP_ID'),
        'authKey' => getenv('CANVAS_ONESIGNAL_AUTH_KEY'),
        'userAuthKey' => getenv('CANVAS_ONESIGNAL_APP_USER_AUTH_KEY')
    ]
];
