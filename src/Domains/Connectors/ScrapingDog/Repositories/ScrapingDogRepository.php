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

    /**
     * Scrape any page and let ScrapingDog's AI extraction return structured JSON.
     *
     * @return array<int|string, mixed>
     */
    public function scrapeWithAi(string $url, string $aiQuery): array
    {
        $response = $this->client->get('/scrape', [
            'query' => [
                'api_key' => $this->defaultParams['api_key'],
                'url' => $url,
                'dynamic' => 'false',
                'ai_query' => $aiQuery,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Best Sellers landing → the department categories with their page url.
     *
     * @return array<int, array{name: string, url: string}>
     */
    public function getBestSellerCategories(string $landingUrl): array
    {
        return $this->scrapeWithAi(
            $landingUrl,
            'Return a JSON array of the best seller department categories, each with its name and relative url path'
        );
    }

    /**
     * A department best-sellers page → the ranked products (name, sku/asin, price, image, rating).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCategoryProducts(string $url): array
    {
        // Keep the field set small — asking for many fields makes the AI truncate the list.
        return $this->scrapeWithAi(
            $url,
            'List all products, each with name, sku and price'
        );
    }
}
