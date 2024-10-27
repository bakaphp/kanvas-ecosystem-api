<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Repositories;

use Baka\Contracts\AppInterface;
use Exception;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\ScrapperApi\Enums\ConfigEnum;

class ScrapperRepository
{
    protected string $baseUri;

    public function __construct(public AppInterface $app)
    {
        $this->baseUri = 'https://api.scraperapi.com';
    }

    protected function makeRequest(string $url, array $queryParams): array
    {
        $response = Http::get($this->baseUri . $url, $queryParams);

        if ($response->failed()) {
            throw new Exception('Request failed with status: ' . $response->status());
        }

        return $response->json();
    }

    public function getByAsin(string $asin): array
    {
        return $this->makeRequest('/structured/amazon/product', [
            'api_key' => $this->app->get(ConfigEnum::SCRAPPER_API_KEY->value),
            'asin' => $asin,
            'country_code' => 'us',
            'tld' => 'com',
        ]);
    }

    public function getSearch(string $search): array
    {
        $response = $this->makeRequest('/structured/amazon/search', [
            'api_key' => $this->app->get(ConfigEnum::SCRAPPER_API_KEY->value),
            'query' => $search,
            'country_code' => 'us',
            'tld' => 'com',
        ]);

        return $response['results'] ?? [];
    }
}
