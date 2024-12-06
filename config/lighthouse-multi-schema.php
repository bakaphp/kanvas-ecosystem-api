<?php

declare(strict_types=1);

/**
 * Configuration for Lighthouse Multi Schema package.
 */
return [
    'multi_schemas' => [
        'schema1' => [
            'route_uri' => '/2025-01-graphql',
            'route_name' => '2025-01-graphql',
            'schema_path' => base_path('graphql/2025-01-schema.graphql'),
            'schema_cache_path' => env('LIGHTHOUSE_SCHEMA1_CACHE_PATH', base_path('bootstrap/cache/schema1-schema.php')),
            'schema_cache_enable' => env('LIGHTHOUSE_SCHEMA1_CACHE_ENABLE', false),
            'middleware' => [
                // Always set the `Accept: application/json` header.
                Nuwave\Lighthouse\Http\Middleware\AcceptJson::class,

                // Logs in a user if they are authenticated. In contrast to Laravel's 'auth'
                // middleware, this delegates auth and permission checks to the field level.
                Nuwave\Lighthouse\Http\Middleware\AttemptAuthentication::class,

                // Schema-specific middleware.
                // Add your custom middleware here for this schema:
                // App\Http\Middleware\ExampleSchemaMiddleware::class,
            ],
        ],
        // Add additional schemas as needed
    ],
];
