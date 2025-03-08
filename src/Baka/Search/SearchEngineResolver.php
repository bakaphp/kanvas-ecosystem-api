<?php

declare(strict_types=1);

namespace Baka\Search;

use Algolia\AlgoliaSearch\SearchClient;
use Algolia\ScoutExtended\Engines\AlgoliaEngine;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;
use Laravel\Scout\Engines\MeilisearchEngine;
use Laravel\Scout\Engines\TypesenseEngine as EnginesTypesenseEngine;
use Meilisearch\Client as MeiliSearchClient;
use Typesense\Client as TypesenseClient;

class SearchEngineResolver
{
    public function __construct(
        protected Application $app,
        protected EngineManager $engineManager
    ) {
        $this->app = $app;
        $this->engineManager = $engineManager;

        $this->registerDynamicEngine();
    }

    protected function registerDynamicEngine(): void
    {
        $this->engineManager->extend('dynamic', function () {
            return $this->resolveEngine();
        });
    }

    public function resolveEngine(?Model $model = null, ?Apps $app = null): Engine
    {
        $app ??= app(Apps::class);
        $defaultEngine = $app->get('search_engine') ?? config('scout.driver', 'algolia');
        // If there's a model, try to get model-specific engine setting
        $modelSpecificEngine = $model !== null ? $app->get($model->getTable() . '_search_engine') : null;
        // Use model-specific engine if available, otherwise use default
        $engine = $modelSpecificEngine ?? $defaultEngine;

        $searchSettings = $app->get($engine . '_search_settings') ?? [];

        return match ($engine) {
            'algolia' => $this->createAlgoliaEngine($searchSettings),
            'typesense' => $this->createTypesenseEngine($searchSettings),
            'meilisearch' => $this->createMeiliSearchEngine($searchSettings),
            default => $this->createAlgoliaEngine($searchSettings),
        };
    }

    protected function createAlgoliaEngine(array $searchSettings): AlgoliaEngine
    {
        $appId = $searchSettings['algolia_app_id'] ?? config('scout.algolia.id');
        $apiKey = $searchSettings['algolia_api_key'] ?? config('scout.algolia.secret');

        //$client = AlgoliaClient::create($appId, $apiKey);
        return new AlgoliaEngine(SearchClient::create($appId, $apiKey));
    }

    protected function createTypesenseEngine(array $searchSettings): EnginesTypesenseEngine
    {
        $apiKey = $searchSettings['typesense_api_key'] ?? config('scout.typesense.api_key');
        $nodes = $searchSettings['typesense_nodes'] ?? [
            [
                'host' => config('scout.typesense.host', 'localhost'),
                'port' => config('scout.typesense.port', 8108),
                'path' => config('scout.typesense.path', '/'),
                'protocol' => config('scout.typesense.protocol', 'http'),
            ],
        ];

        $connectionTimeout = $searchSettings['typesense_timeout']
            ?? config('scout.typesense.connection_timeout_seconds', 2);

        $config = [
            'api_key' => $apiKey,
            'nodes' => $nodes,
            'connection_timeout_seconds' => $connectionTimeout,
        ];

        $maxItemsPerPage = $searchSettings['typesense_max_items_per_page'] ?? 1000;

        $client = new TypesenseClient($config);

        // Assuming the constructor takes a client and a chunk size
        return new EnginesTypesenseEngine($client, $maxItemsPerPage);
    }

    protected function createMeiliSearchEngine(array $searchSettings): MeilisearchEngine
    {
        $host = $searchSettings['meilisearch_host'] ?? config('scout.meilisearch.host', 'http://localhost:7700');
        $key = $searchSettings['meilisearch_key'] ?? config('scout.meilisearch.key', null);

        $client = new MeiliSearchClient($host, $key);

        return new MeiliSearchEngine($client);
    }
}
