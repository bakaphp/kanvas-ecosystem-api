<?php

declare(strict_types=1);

return [
    /*
     * Schemes the server is allowed to fetch. Anything else (file://, gopher://,
     * dict://, data:, ftp://, ...) is rejected before any DNS resolution happens.
     */
    'allowed_schemes' => ['http', 'https'],

    'max_bytes' => (int) env('SSRF_MAX_BYTES', 50 * 1024 * 1024),
    'timeout' => (float) env('SSRF_TIMEOUT', 15),
    'connect_timeout' => (float) env('SSRF_CONNECT_TIMEOUT', 5),
    'max_redirects' => (int) env('SSRF_MAX_REDIRECTS', 3),

    /*
     * Operator-specific extra ranges to block, merged on top of the hardcoded baseline in
     * Baka\Http\SafeUrl::RESERVED_CIDRS (CGNAT, TEST-NET, IPv6 transition forms, etc.). The
     * baseline is in code so the security floor survives a config-load failure; add app- or
     * environment-specific blocks here (e.g. an internal VPC supernet).
     */
    'blocked_cidrs' => [],
];
