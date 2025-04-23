<?php

use Kanvas\Souk\Cart\Support\RedisStorage;

return [
    /*
     * ---------------------------------------------------------------
     * Formatting
     * ---------------------------------------------------------------
     */
    'format_numbers' => env('LARAVEL_CART_FORMAT_VALUES', false),

    'decimals' => env('LARAVEL_CART_DECIMALS', 0),

    'round_mode' => env('LARAVEL_CART_ROUND_MODE', 'down'),

    'dec_point' => env('SHOPPING_DEC_POINT', '.'),

    'thousands_sep' => env('SHOPPING_THOUSANDS_SEP', ','),
    /*
     * ---------------------------------------------------------------
     * Storage
     * ---------------------------------------------------------------
     */
    'driver' => 'database',

    'storage' => [
        'redis' => RedisStorage::class,
        'session',
        'database' => [
            'model'      => \Kanvas\Souk\Cart\Support\RedisStorage::class,
            'id'         => 'session_id',
            'items'      => 'items',
            'conditions' => 'conditions',
        ],
    ],
];
