<?php

declare(strict_types=1);

return [
    'search_timeout_ms' => (int) env('INSURANCE_SEARCH_TIMEOUT_MS', 8000),
    'search_cache_ttl' => (int) env('INSURANCE_SEARCH_CACHE_TTL', 300),
    'quote_binding_ttl' => (int) env('INSURANCE_QUOTE_BINDING_TTL', 1800),
    'task_workers' => (int) env('INSURANCE_TASK_WORKERS', 2),
];
