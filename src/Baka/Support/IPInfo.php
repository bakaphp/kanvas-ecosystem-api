<?php

declare(strict_types=1);

namespace Baka\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class IPInfo
{
    protected string $apiToken;

    public function __construct()
    {
        $this->apiToken = config('kanvas.ipinfo.token'); // Add token to config/services.php
    }

    public function getIpInfo(string $ipAddress): ?array
    {
        $url = "https://ipinfo.io/{$ipAddress}";

        $response = Http::withToken($this->apiToken)->get($url);

        if ($response->successful()) {
            return $response->json();
        }

        return null;
    }

    /**
     * Get the real client IP address behind proxies/load balancers.
     *
     * Laravel's $request->ip() returns the proxy IP when behind AWS ALB/ELB or Nginx,
     * even with TrustProxies configured. This method reads the X-Forwarded-For header
     * directly to extract the original client IP (first entry in the chain).
     */
    public static function getClientIp(?Request $request = null): string
    {
        $request = $request ?? request();

        $forwardedFor = $request->header('X-Forwarded-For', '');

        if (is_string($forwardedFor) && $forwardedFor !== '') {
            return trim(explode(',', $forwardedFor)[0]);
        }

        return (string) $request->ip();
    }
}
