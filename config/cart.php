<?php

use Kanvas\Souk\Cart\Support\RedisStorage;

return [
    /*
     * ---------------------------------------------------------------
     * Formatting
     * ---------------------------------------------------------------
     */
    'format_numbers' => env('SHOPPING_FORMAT_VALUES', false),

    'decimals' => env('SHOPPING_DECIMALS', 2),

    'dec_point' => env('SHOPPING_DEC_POINT', '.'),

    'thousands_sep' => env('SHOPPING_THOUSANDS_SEP', ','),

    /*
     * ---------------------------------------------------------------
     * persistence
     * ---------------------------------------------------------------
     *
     * the configuration for persisting cart
     */
    'storage' => RedisStorage::class,

    /*
     * ---------------------------------------------------------------
     * Events
     * ---------------------------------------------------------------
     */
    'events' => null,
];
