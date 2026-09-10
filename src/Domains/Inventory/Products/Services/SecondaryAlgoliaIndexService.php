<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Services;

use Algolia\AlgoliaSearch\Api\SearchClient;
use Baka\Search\SearchEngineResolver;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Models\Products;

/**
 * Writes a Product directly into an Algolia index OTHER than its normal `searchableAs()` index —
 * Scout/KanvasAlgoliaEngine has no concept of a second index per save, so this bypasses them
 * entirely and talks to the Algolia SDK directly, the same way RecombeeProductIndexService bypasses
 * Scout for its own external catalog.
 */
class SecondaryAlgoliaIndexService
{
    private SearchClient $client;

    public function __construct(private Apps $app)
    {
        $searchSettings = $this->app->get('algolia_search_settings') ?? [];
        $credentials = SearchEngineResolver::algoliaCredentialsFromSettings($searchSettings);

        $this->client = SearchClient::create($credentials['app_id'], $credentials['api_key']);
    }

    public function indexProduct(Products $product, string $indexName): bool
    {
        $this->client->saveObject($indexName, $product->toSearchableArray());

        return true;
    }

    public function removeProduct(Products $product, string $indexName): bool
    {
        $this->client->deleteObject($indexName, $product->uuid);

        return true;
    }
}
