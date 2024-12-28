<?php

declare(strict_types=1);

namespace Baka\Support;

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
}
