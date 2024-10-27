<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Repositories;

use Baka\Contracts\AppInterface;
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
        $query = http_build_query($queryParams);
        $fullUrl = $this->baseUri . $url . '?' . $query;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new \Exception(curl_error($ch));
        }

        curl_close($ch);

        return json_decode($response, true);
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
