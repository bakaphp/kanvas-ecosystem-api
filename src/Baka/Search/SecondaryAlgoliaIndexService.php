<?php

declare(strict_types=1);

namespace Baka\Search;

use Algolia\AlgoliaSearch\Api\SearchClient;
use Baka\Search\Contracts\SecondaryIndexServiceInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;

/**
 * Writes any Scout-searchable entity directly into an Algolia index OTHER than its normal
 * `searchableAs()` index — Scout/KanvasAlgoliaEngine has no concept of a second index per save, so
 * this bypasses them entirely and talks to the Algolia SDK directly, the same way
 * RecombeeProductIndexService bypasses Scout for its own external catalog.
 *
 * The delete key comes from the entity's own `toSearchableArray()['objectID']` rather than a fixed
 * model property — different entities key their Algolia record differently (Products uses `uuid`),
 * and `toSearchableArray()` is the one contract every Scout-searchable model actually implements.
 */
class SecondaryAlgoliaIndexService implements SecondaryIndexServiceInterface
{
    private SearchClient $client;

    public function __construct(private Apps $app, ?SearchClient $client = null)
    {
        $this->client = $client ?? $this->buildClient();
    }

    public function indexEntity(Model $entity, string $indexName): void
    {
        $this->client->saveObject($indexName, $entity->toSearchableArray());
    }

    public function removeEntity(Model $entity, string $indexName): void
    {
        $this->client->deleteObject($indexName, (string) $entity->toSearchableArray()['objectID']);
    }

    private function buildClient(): SearchClient
    {
        $searchSettings = $this->app->get('algolia_search_settings') ?? [];
        $credentials = SearchEngineResolver::algoliaCredentialsFromSettings($searchSettings);

        return SearchClient::create($credentials['app_id'], $credentials['api_key']);
    }
}
