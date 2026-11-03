<?php

declare(strict_types=1);

return [
    'ws_endpoint' => env('BROWSER_WS_ENDPOINT'),
    'navigation_timeout' => (int) env('BROWSER_NAVIGATION_TIMEOUT', 30000),
    'action_timeout' => (int) env('BROWSER_ACTION_TIMEOUT', 15000),
    'snapshot_max_text' => (int) env('BROWSER_SNAPSHOT_MAX_TEXT', 20000),
    'snapshot_max_elements' => (int) env('BROWSER_SNAPSHOT_MAX_ELEMENTS', 150),
    'allowed_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('BROWSER_ALLOWED_HOSTS', '')),
    ))),
];
