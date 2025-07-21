<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Repositories;

use Baka\Contracts\AppInterface;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\PromiseInterface;
use Kanvas\Connectors\ScrapperApi\Enums\ConfigEnum;

class ScrapperRepository
{
    protected Client $client;
    protected string $baseUri = 'https://api.scraperapi.com';
    protected array $defaultParams;

    public function __construct(public AppInterface $app)
    {
        $this->client = new Client([
            'base_uri' => $this->baseUri,
            'timeout' => 60,
        ]);

        $this->defaultParams = [
            'api_key' => $this->app->get(ConfigEnum::SCRAPPER_API_KEY->value),
            'country_code' => 'us',
            'tld' => 'com',
        ];
    }

    public function makeRequestAsync(string $endpoint, array $params = []): PromiseInterface
    {
        $query = array_merge($this->defaultParams, $params);

        return $this->client->getAsync($endpoint, ['query' => $query])
            ->then(
                fn ($response) => json_decode($response->getBody()->getContents(), true),
                function (RequestException $e) use ($endpoint) {
                    logger()->error("Request failed for {$endpoint}", [
                        'message' => $e->getMessage(),
                        'response' => $e->getResponse()?->getBody()?->getContents(),
                    ]);

                    throw new Exception("Request failed: {$e->getMessage()}");
                }
            );
    }

    public function getByAsin(string $asin): array
    {
        $promises = $this->makeRequestAsync('/structured/amazon/product', [
            'asin' => $asin,
        ]);
        $results = \GuzzleHttp\Promise\Utils::settle($promises)->wait();
        return $results[0]['value'];
    }

    public function getSearch(string $search): array
    {
        $data = $this->makeRequestAsync('/structured/amazon/search', [
            'query' => $search,
        ])->wait();

        return $data['results'] ?? [];
    }

    public function getMultipleByAsin(array $asins): array
    {
        $promises = [];

        foreach ($asins as $asin) {
            $promises[] = $this->makeRequestAsync('/structured/amazon/product', [
                'asin' => $asin,
            ]);
        }

        $results = \GuzzleHttp\Promise\Utils::settle($promises)->wait();

        return array_map(function ($result) {
            return $result['value'] ?? null;
        }, $results);
    }
}
