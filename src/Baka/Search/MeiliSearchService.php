<?php

declare(strict_types=1);

namespace Baka\Search;

use Illuminate\Database\Eloquent\Model;
use Meilisearch\Client;

class MeiliSearchService
{
    protected Client $meiliClient;

    public function __construct()
    {
        $this->meiliClient = new Client(
            config('scout.meilisearch.host'),
            config('scout.meilisearch.key')
        );
    }

    public function indexModel(string $indexName, Model $entity): void
    {
        $documents = array_merge(
            [
                'id' => $entity->getKey(),
            ],
            $entity->toSearchableArray()
        );

        $primaryKey = $entity->getKeyName();
        $index = $this->meiliClient->index($indexName);
        $index->addDocuments($documents, $primaryKey);
    }

    public function deleteRecord(string $indexName, Model $entity): array
    {
        $index = $this->meiliClient->index($indexName);

        return $index->deleteDocument($entity->getKey());
    }
}
