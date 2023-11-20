<?php

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

    public function indexModel(string $indexName, Model $model): void
    {
        $documents = array_merge(
            [
                'id' => $model->getKey(),
            ],
            $model->toSearchableArray()
        );

        $primaryKey = $model->getKeyName();
        $index = $this->meiliClient->index($indexName);
        $index->addDocuments($documents, $primaryKey);
    }
}
