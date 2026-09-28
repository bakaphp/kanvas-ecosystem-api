<?php

declare(strict_types=1);

namespace Baka\Search;

use Baka\Search\Contracts\SecondaryIndexServiceInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Typesense\Client;
use Typesense\Exceptions\ObjectNotFound;

/**
 * Writes any Scout-searchable entity directly into a Typesense collection OTHER than its normal
 * `searchableAs()` collection — same bypass-Scout rationale as SecondaryAlgoliaIndexService, for
 * Typesense instead.
 *
 * Unlike Algolia (which auto-creates an index on first write), Typesense requires the target
 * collection to already exist with a schema — upserting into a missing one throws a client error.
 * Provisioning that collection is out of scope here; the caller does it beforehand.
 *
 * The delete key comes from the entity's own `toSearchableArray()['id']` rather than a fixed model
 * property — `toSearchableArray()` is the one contract every Scout-searchable model implements, and
 * Typesense's own document-id convention is that `id` field, not the model's primary key column.
 */
class SecondaryTypesenseIndexService implements SecondaryIndexServiceInterface
{
    private Client $client;

    public function __construct(private Apps $app, ?Client $client = null)
    {
        $this->client = $client ?? $this->buildClient();
    }

    public function indexEntity(Model $entity, string $indexName): void
    {
        $this->client->getCollections()[$indexName]->getDocuments()->upsert($entity->toSearchableArray());
    }

    public function removeEntity(Model $entity, string $indexName): void
    {
        $documentId = (string) $entity->toSearchableArray()['id'];

        try {
            $this->client->getCollections()[$indexName]->getDocuments()[$documentId]->delete();
        } catch (ObjectNotFound) {
            // Already gone — removing an entity that was never indexed there is a no-op, not a failure.
        }
    }

    private function buildClient(): Client
    {
        $searchSettings = $this->app->get('typesense_search_settings') ?? [];

        return SearchEngineResolver::getTypesenseClient($searchSettings);
    }
}
