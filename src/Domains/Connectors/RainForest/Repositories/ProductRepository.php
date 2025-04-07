<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RainForest\Repositories;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\RainForest\Client;
use Kanvas\Connectors\RainForest\Enums\ConfigurationEnum;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

class ProductRepository
{
    public function __construct(
        public AppInterface $app,
        public Warehouses $warehouse,
        public Channels $channels
    ) {
    }

    public function getByTerm(string $name): array
    {
        $client = Client::getClient();
        $response = $client->get('/request', [
            'query' => [
                'api_key' => $this->app->get(ConfigurationEnum::RAINFOREST_KEY->value),
                'type' => 'search',
                'amazon_domain' => 'amazon.com',
                'search_term' => $name,
                'sort_by' => 'featured',
                'exclude_sponsored' => true,
            ],
        ]);
        $response = json_decode($response->getBody()->getContents(), true);

        return $response['search_results'];
    }

    public function getByAsin(string $asin): array
    {
        $client = Client::getClient();
        $response = $client->get('/request', [
            'query' => [
                'api_key' => $this->app->get(ConfigurationEnum::RAINFOREST_KEY->value),
                'type' => 'product',
                'amazon_domain' => 'amazon.com',
                'asin' => $asin,
            ],
        ]);
        $response = json_decode($response->getBody()->getContents(), true);

        return $response['product'];
    }
}
