<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for all database work. Of course
    | you may use many connections at once using the Database library.
    |
    */

    'default' => env('DB_CONNECTION', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Here are each of the database connections setup for your application.
    | Of course, examples of configuring each database platform that is
    | supported by Laravel is shown below to make development simple.
    |
    |
    | All database work in Laravel is done through the PHP PDO facilities
    | so make sure you have the driver for your particular database of
    | choice installed on your machine before you begin development.
    |
    */

    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'read' => [
                'host' => [
                    env('DB_HOST_READ', '127.0.0.1'),
                ],
            ],
            'write' => [
                'host' => [
                    env('DB_HOST', '127.0.0.1'),
                ],
            ],
            'sticky' => true,
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'kanvas'),
            'username' => env('DB_USERNAME', 'kanvas'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_520_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => 'InnoDB',
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],
        'ecosystem' => [
            'driver' => 'mysql',
            'read' => [
                'host' => [
                    env('DB_HOST_READ', '127.0.0.1'),
                ],
            ],
            'write' => [
                'host' => [
                    env('DB_HOST', '127.0.0.1'),
                ],
            ],
            'sticky' => true,
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'kanvas'),
            'username' => env('DB_USERNAME', 'kanvas'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_520_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => 'InnoDB',
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],
        'inventory' => [
            'driver' => 'mysql',
            'read' => [
                'host' => [
                    env('DB_INVENTORY_HOST_READ', '127.0.0.1'),
                ],
            ],
            'write' => [
                'host' => [
                    env('DB_INVENTORY_HOST', '127.0.0.1'),
                ],
            ],
            'sticky' => true,
            'port' => env('DB_INVENTORY_PORT', '3306'),
            'database' => env('DB_INVENTORY_DATABASE', 'inventory'),
            'username' => env('DB_INVENTORY_USERNAME', 'kanvas'),
            'password' => env('DB_INVENTORY_PASSWORD', ''),
            'unix_socket' => env('DB_INVENTORY_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_520_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => 'InnoDB',
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],
        'social' => [
            'driver' => 'mysql',
            'read' => [
                'host' => [
                    env('DB_SOCIAL_HOST_READ', '127.0.0.1'),
                ],
            ],
            'write' => [
                'host' => [
                    env('DB_SOCIAL_HOST', '127.0.0.1'),
                ],
            ],
            'sticky' => true,
            'port' => env('DB_SOCIAL_PORT', '3306'),
            'database' => env('DB_SOCIAL_DATABASE', 'social'),
            'username' => env('DB_SOCIAL_USERNAME', 'kanvas'),
            'password' => env('DB_SOCIAL_PASSWORD', ''),
            'unix_socket' => env('DB_SOCIAL_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_520_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => 'InnoDB',
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],
        'crm' => [
            'driver' => 'mysql',
            'read' => [
                'host' => [
                    env('DB_CRM_HOST_READ', '127.0.0.1'),
                ],
            ],
            'write' => [
                'host' => [
                    env('DB_CRM_HOST', '127.0.0.1'),
                ],
            ],
            'sticky' => true,
            'port' => env('DB_CRM_PORT', '3306'),
            'database' => env('DB_CRM_DATABASE', 'social'),
            'username' => env('DB_CRM_USERNAME', 'kanvas'),
            'password' => env('DB_CRM_PASSWORD', ''),
            'unix_socket' => env('DB_CRM_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_520_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => 'InnoDB',
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run in the database.
    |
    */

    'migrations' => 'migrations',

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as APC or Memcached. Laravel makes it easy to dig right in.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_') . '_database_'),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

        'model-cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => 2,
        ],

    ],

];
