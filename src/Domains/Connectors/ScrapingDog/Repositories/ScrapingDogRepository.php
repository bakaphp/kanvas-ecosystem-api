<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapingDog\Repositories;

use Baka\Contracts\AppInterface;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\PromiseInterface;
use Kanvas\Connectors\ScrapingDog\Enums\ConfigEnum;

class ScrapingDogRepository
{
    protected Client $client;
    protected string $baseUri = 'https://api.scrapingdog.com';
    protected array $defaultParams;

    public function __construct(public AppInterface $app)
    {
        $this->client = new Client([
            'base_uri' => $this->baseUri,
            'timeout' => 60,
        ]);

        $this->defaultParams = [
            'api_key' => $this->app->get(ConfigEnum::SCRAPING_DOG_API_KEY->value),
            'domain' => 'com',
            'country' => 'us',
            'postal_code' => '',
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
        $promise = $this->makeRequestAsync('/amazon/product', [
            'asin' => $asin,
        ]);

        $result = $promise->wait();

        return $result ?? [];
    }

    public function getSearch(string $query, int $page = 1): array
    {
        $data = $this->makeRequestAsync('/amazon/search', [
            'query' => $query,
            'page' => (string)$page,
        ])->wait();

        return $data ?? [];
    }

    public function getMultipleByAsin(array $asins): array
    {
        $promises = [];

        foreach ($asins as $asin) {
            $promises[] = $this->makeRequestAsync('/amazon/product', [
                'asin' => $asin,
            ]);
        }

        $results = \GuzzleHttp\Promise\Utils::settle($promises)->wait();

        return array_map(function ($result) {
            if ($result['state'] === 'fulfilled') {
                return $result['value'] ?? null;
            }

            // Log error if promise was rejected
            if (isset($result['reason'])) {
                logger()->error('Promise rejected for ASIN request', [
                    'reason' => $result['reason']->getMessage(),
                ]);
            }

            return null;
        }, $results);
    }
}
