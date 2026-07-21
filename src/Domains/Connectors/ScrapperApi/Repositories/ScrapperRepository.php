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

    /**
     * Render any Amazon page (e.g. a Best Sellers list) through the generic endpoint.
     * There is no structured best-sellers endpoint, so this returns the page as
     * markdown/HTML text — parse it (see extractAsins) before hydrating products.
     */
    public function getRenderedPage(string $url, string $outputFormat = 'markdown'): string
    {
        $query = array_merge($this->defaultParams, [
            'url' => $url,
            'output_format' => $outputFormat,
        ]);

        return $this->client->get('/', ['query' => $query])->getBody()->getContents();
    }

    /**
     * Best Sellers pages have no structured endpoint: render to markdown, pull the
     * ASINs in ranked order, then reuse the structured product endpoint for clean data.
     */
    public function getTopBestSellers(string $bestSellersUrl, int $limit = 50): array
    {
        $asins = self::extractAsins($this->getRenderedPage($bestSellersUrl), $limit);

        return empty($asins) ? [] : $this->getMultipleByAsin($asins);
    }

    /**
     * Pull ASINs from rendered Amazon page text, preserving page (rank) order.
     *
     * @return array<int, string>
     */
    public static function extractAsins(string $content, int $limit = 50): array
    {
        preg_match_all('#/(?:dp|gp/product)/([A-Z0-9]{10})#', $content, $matches);

        $asins = array_values(array_unique($matches[1]));

        return array_slice($asins, 0, $limit);
    }
}
