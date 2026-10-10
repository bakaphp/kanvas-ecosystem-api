<?php

declare(strict_types=1);

return [
    'search_timeout_ms' => (int) env('INSURANCE_SEARCH_TIMEOUT_MS', 8000),
    'search_cache_ttl' => (int) env('INSURANCE_SEARCH_CACHE_TTL', 300),
    'quote_binding_ttl' => (int) env('INSURANCE_QUOTE_BINDING_TTL', 1800),
    'task_workers' => (int) env('INSURANCE_TASK_WORKERS', 2),
    'humano' => [
        'search_enabled' => filter_var(env('INSURANCE_HUMANO_SEARCH_ENABLED', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
        'default_rc_exceso' => env('INSURANCE_HUMANO_RC_EXCESO', '1'),
        'default_suma_asegurada_auto_exceso' => env('INSURANCE_HUMANO_SUMA_AUTO_EXCESO', '5000000'),
        'default_suma_asegurada_moto_exceso' => env('INSURANCE_HUMANO_SUMA_MOTO_EXCESO', '3000000'),
    ],
];
