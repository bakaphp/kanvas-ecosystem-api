<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Services;

use Algolia\AlgoliaSearch\Api\SearchClient;
use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Search\SearchEngineResolver;
use Illuminate\Support\Facades\DB;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\Enrichment\Agents\ProductEnrichmentAgent;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum;
use Kanvas\Inventory\Recommendations\Enums\SearchFieldEnum;
use Kanvas\Inventory\Recommendations\Enums\SemanticProfileStrategyEnum;
use Throwable;

/**
 * Reports whether discovery is actually wired up for a tenant, and what to do
 * about each gap.
 *
 * Setup is ordered and the order is not obvious — the embed field is fixed at
 * collection creation, blurbs must exist before indexing is worth anything —
 * so the failure mode is a tenant that looks configured and quietly returns
 * keyword matches. Each check names its own fix.
 */
class ProductDiscoveryStatusService
{
    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
        private ?SearchClient $algolia = null,
    ) {
    }

    /**
     * `flags` carry where each value came from, because "off" at the app and "off" because the
     * company said so need different fixes.
     *
     * @return array{
     *     ready: bool,
     *     checks: list<array{key: string, ok: bool, detail: string, fix: ?string}>,
     *     flags: array<string, array{value: ?bool, source: string}>
     * }
     */
    public function report(): array
    {
        $checks = match (ProductDiscoveryResolver::engineFor($this->app)) {
            ProductDiscoveryResolver::ENGINE_ALGOLIA => $this->algoliaChecks(),
            default => $this->typesenseChecks(),
        };

        return [
            'ready' => ! in_array(false, array_column($checks, 'ok'), true),
            'checks' => $checks,
            'flags' => $this->flags(),
        ];
    }

    private function typesenseChecks(): array
    {
        return [
            $this->engineCheck(),
            $this->credentialsCheck(),
            $this->indexNameCheck(),
            $this->collectionCheck(),
            $this->queryByCheck(),
            $this->strategyCheck(),
            $this->enrichmentAgentCheck(),
            $this->blurbCoverageCheck(),
            $this->enrichmentTriggerCheck(),
        ];
    }

    private function algoliaChecks(): array
    {
        $settings = $this->algoliaSettings();

        return [
            $this->check('search_engine', true, 'resolved engine: algolia', null),
            $this->algoliaCredentialsCheck(),
            $this->indexNameCheck(),
            $this->algoliaIndexCheck($settings),
            $this->algoliaSearchableBlurbCheck($settings),
            $this->algoliaAudienceFacetCheck($settings),
            $this->rangeFiltersCheck(),
            $this->strategyCheck(),
            $this->enrichmentAgentCheck(),
            $this->blurbCoverageCheck(),
            $this->enrichmentTriggerCheck(),
        ];
    }

    /**
     * @return array<string, array{value: ?bool, source: string}>
     */
    private function flags(): array
    {
        $settings = ProductDiscoverySettings::for($this->app, $this->company);

        return [
            ConfigurationEnum::PRODUCT_ENRICHMENT_ENABLED->value => [
                'value' => $settings->enrichmentEnabled(),
                'source' => $settings->source(ConfigurationEnum::PRODUCT_ENRICHMENT_ENABLED->value),
            ],
            ConfigurationEnum::PRODUCT_DISCOVERY_ENABLED->value => [
                'value' => $settings->discoveryEnabled(),
                'source' => $settings->source(ConfigurationEnum::PRODUCT_DISCOVERY_ENABLED->value),
            ],
        ];
    }

    private function algoliaCredentialsCheck(): array
    {
        try {
            $this->algoliaClient()->listIndices(0, 1);

            return $this->check(
                'algolia_credentials',
                true,
                'Algolia reachable',
                null
            );
        } catch (Throwable $e) {
            return $this->check(
                'algolia_credentials',
                false,
                'Algolia rejected the call: ' . $e->getMessage(),
                'set algolia_search_settings.algolia_app_id and algolia_api_key (an admin key, since setup writes index settings)',
            );
        }
    }

    /**
     * @return array{settings: array<string, mixed>, error: ?string}
     */
    private function algoliaSettings(): array
    {
        try {
            return [
                'settings' => (array) $this->algoliaClient()->getSettings(ProductDiscoveryResolver::collectionName($this->app)),
                'error' => null,
            ];
        } catch (Throwable $e) {
            return ['settings' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * @param array{settings: array<string, mixed>, error: ?string} $settings
     */
    private function algoliaIndexCheck(array $settings): array
    {
        $name = ProductDiscoveryResolver::collectionName($this->app);

        return $this->check(
            'algolia_index',
            $settings['error'] === null,
            $settings['error'] === null ? "'{$name}' readable" : "'{$name}' unreadable: " . $settings['error'],
            'reindex to create it: kanvas-inventory:scout-product-index-process ' . $this->app->getId() . ' --action=reindex',
        );
    }

    /**
     * @param array{settings: array<string, mixed>, error: ?string} $settings
     */
    private function algoliaSearchableBlurbCheck(array $settings): array
    {
        $searchable = AlgoliaDiscoveryIndexService::settingList($settings['settings'], 'searchableAttributes');

        if ($settings['error'] === null && $searchable === []) {
            return $this->check(
                'algolia_searchable_blurb',
                true,
                'no explicit list: every attribute is searched, blurb included',
                null
            );
        }

        return $this->check(
            'algolia_searchable_blurb',
            AlgoliaDiscoveryIndexService::declares($searchable, SearchFieldEnum::BLURB->value),
            SearchFieldEnum::BLURB->value . ' in searchableAttributes',
            'run kanvas-inventory:prepare-discovery-index ' . $this->app->getId(),
        );
    }

    /**
     * @param array{settings: array<string, mixed>, error: ?string} $settings
     */
    private function algoliaAudienceFacetCheck(array $settings): array
    {
        $facets = AlgoliaDiscoveryIndexService::settingList($settings['settings'], 'attributesForFaceting');

        return $this->check(
            'algolia_audience_facet',
            AlgoliaDiscoveryIndexService::declares($facets, SearchFieldEnum::AUDIENCE->value),
            SearchFieldEnum::AUDIENCE->value . ' in attributesForFaceting',
            'run kanvas-inventory:prepare-discovery-index ' . $this->app->getId(),
        );
    }

    private function rangeFiltersCheck(): array
    {
        $verified = $this->app->get(ConfigurationEnum::RANGE_FILTERS->value);
        $needed = Products::DISCOVERY_SCHEMA_VERSION;
        $ok = is_numeric($verified) && (int) $verified >= $needed;

        return $this->check(
            'range_filters',
            $ok,
            'verified version ' . (is_numeric($verified) ? (int) $verified : 'none') . ", code needs {$needed}",
            'prepare, reindex, then verify: kanvas-inventory:prepare-discovery-index ' . $this->app->getId()
                . '; kanvas-inventory:scout-product-index-process ' . $this->app->getId() . ' --action=reindex'
                . '; kanvas-inventory:verify-discovery-index ' . $this->app->getId(),
        );
    }

    private function algoliaClient(): SearchClient
    {
        return $this->algolia ??= SearchEngineResolver::algoliaClient($this->app);
    }

    private function engineCheck(): array
    {
        $engine = $this->app->get('products_search_engine')
            ?? $this->app->get('search_engine')
            ?? config('scout.driver');

        return $this->check(
            'search_engine',
            $engine === 'typesense',
            'resolved engine: ' . (is_string($engine) ? $engine : 'none'),
            "set products_search_engine to 'typesense'",
        );
    }

    private function credentialsCheck(): array
    {
        $settings = SearchEngineResolver::typesenseSettings($this->app);

        if (! SearchEngineResolver::hasTypesenseCredentials($settings)) {
            return $this->check('typesense_credentials', false, 'no api key', 'set typesense_search_settings.typesense_api_key');
        }

        try {
            $health = SearchEngineResolver::getTypesenseClient($settings)->health->retrieve();

            return $this->check('typesense_credentials', (bool) ($health['ok'] ?? false), 'cluster reachable', null);
        } catch (Throwable $e) {
            return $this->check('typesense_credentials', false, 'unreachable: ' . $e->getMessage(), 'check typesense_search_settings.typesense_nodes');
        }
    }

    private function indexNameCheck(): array
    {
        $custom = $this->app->get('app_custom_product_index');

        return $this->check(
            'app_custom_product_index',
            is_string($custom) && $custom !== '',
            is_string($custom) && $custom !== '' ? $custom : 'shared product_index',
            'set app_custom_product_index so this app does not share a collection with other tenants',
        );
    }

    /**
     * The embed field only exists if it was declared when the collection was
     * created, so "missing" here always means drop and reindex.
     */
    private function collectionCheck(): array
    {
        $name = ProductDiscoveryResolver::collectionName($this->app);

        try {
            $collection = SearchEngineResolver::typesenseClient($this->app)
                ->collections[$name]
                ->retrieve();
        } catch (Throwable) {
            return $this->check('collection', false, "'{$name}' does not exist", 'run the reindex command to create it');
        }

        $docs = (int) ($collection['num_documents'] ?? 0);
        $fields = array_column($collection['fields'] ?? [], 'name');
        $hasEmbedding = in_array('embedding', $fields, true);

        if ($docs === 0) {
            return $this->check('collection', false, "'{$name}' exists but is empty", 'run the reindex command');
        }

        // A collection built before these fields existed cannot be filtered or
        // queried at all — discovery errors rather than degrading.
        $missing = array_values(array_diff(['search_blurb', 'price', 'in_stock', 'companies_id'], $fields));

        if ($missing !== []) {
            return $this->check(
                'collection',
                false,
                "'{$name}': {$docs} docs, missing " . implode(', ', $missing),
                'this collection predates the discovery fields — DROP it and reindex, or every search fails',
            );
        }

        // Reindexing alone does NOT add a field to a collection that already exists.
        if (! in_array(SearchFieldEnum::AUDIENCE->value, $fields, true)) {
            return $this->check(
                'collection',
                false,
                "'{$name}': {$docs} docs, no audience field — recipient filtering is off",
                'PATCH the collection to add it (reindexing will NOT): '
                    . "curl -X PATCH \$HOST/collections/{$name} -d '{\"fields\":[{\"name\":\"audience\",\"type\":\"string[]\",\"optional\":true,\"facet\":true}]}'",
            );
        }

        if (! $hasEmbedding) {
            return $this->check(
                'collection',
                false,
                "'{$name}': {$docs} docs, no embedding field",
                'set product_discovery_embedding_model, then DROP the collection and reindex — the embed field is fixed at creation',
            );
        }

        return $this->check('collection', true, "'{$name}': {$docs} docs, embedding present", null);
    }

    private function queryByCheck(): array
    {
        $queryBy = (string) ($this->app->get(ConfigurationEnum::TYPESENSE_QUERY_BY->value)
            ?: config('inventory-discovery.typesense_query_by', ''));
        $names = array_map('trim', explode(',', $queryBy));

        if (! in_array('search_blurb', $names, true)) {
            return $this->check('query_by', false, $queryBy, 'include search_blurb — it is what semantic matching reads');
        }

        return $this->check(
            'query_by',
            true,
            $queryBy . (in_array('embedding', $names, true) ? ' (hybrid)' : ' (lexical only)'),
            in_array('embedding', $names, true) ? null : 'add `embedding` once the collection declares it, for cross-language matching',
        );
    }

    /**
     * Not a failure — `generic` is a valid answer. It is reported because a gift
     * catalog left on `generic` produces blurbs written for the wrong reader,
     * which is invisible unless someone looks.
     */
    private function strategyCheck(): array
    {
        $strategy = SemanticProfileStrategyEnum::fromApp(
            $this->app->get(ConfigurationEnum::SEMANTIC_PROFILE_STRATEGY->value),
        );

        return $this->check(
            'catalog_strategy',
            true,
            $strategy->value . ' — blurbs are written for "' . mb_strimwidth($strategy->blurbFraming(), 0, 60, '…') . '"',
            null,
        );
    }

    private function enrichmentAgentCheck(): array
    {
        /** @var Agent|null $agent */
        $agent = Agent::fromApp($this->app)
            ->notDeleted()
            ->whereHas('type', fn ($q) => $q->where('handler', ProductEnrichmentAgent::class))
            ->first();

        if ($agent === null) {
            return $this->check('enrichment_agent', false, 'none', 'create an Agent of type "Product Enrichment"');
        }

        // A record with its own instructions replaces the shipped prompt whole,
        // which is the usual reason an agent "ignores" its design.
        if (! empty($agent->instructions)) {
            return $this->check(
                'enrichment_agent',
                false,
                "#{$agent->getId()} has custom instructions",
                'clear the agent instructions field so it keeps the shipped enrichment prompt',
            );
        }

        return $this->check('enrichment_agent', true, "#{$agent->getId()} {$agent->name}", null);
    }

    private function blurbCoverageCheck(): array
    {
        $total = (int) DB::connection('inventory')
            ->table('products')
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', 0)
            ->where('is_published', 1)
            ->count();

        if ($total === 0) {
            return $this->check('blurb_coverage', false, 'no published products', 'nothing to enrich in this company');
        }

        $enriched = (int) DB::table('apps_custom_fields')
            ->where('companies_id', $this->company->getId())
            ->where('name', SearchFieldEnum::BLURB->value)
            ->where('value', '<>', '')
            ->count();

        $pct = (int) round($enriched / $total * 100);

        return $this->check(
            'blurb_coverage',
            $pct >= 90,
            "{$enriched}/{$total} ({$pct}%)",
            'run ' . $this->backfillCommand() . ' — below full coverage the un-enriched majority outranks the rest',
        );
    }

    /**
     * Enrichment runs from the product actions only when the flag is explicitly on; without it new and
     * edited products keep their old blurb (or none) after the backfill.
     */
    private function enrichmentTriggerCheck(): array
    {
        $settings = ProductDiscoverySettings::for($this->app, $this->company);
        $on = $settings->enrichmentExplicitlyOn();

        return $this->check(
            'enrichment_trigger',
            $on,
            $on
                ? 'on (' . $settings->source(ConfigurationEnum::PRODUCT_ENRICHMENT_ENABLED->value) . ')'
                : 'off — new and edited products are not enriched',
            'set the company setting ' . ConfigurationEnum::PRODUCT_ENRICHMENT_ENABLED->value . ' = true, then run ' . $this->backfillCommand(),
        );
    }

    private function backfillCommand(): string
    {
        return 'kanvas-inventory:backfill-product-enrichment ' . $this->app->getId() . ' --company_id=' . $this->company->getId();
    }

    private function check(string $key, bool $ok, string $detail, ?string $fix): array
    {
        return [
            'key' => $key,
            'ok' => $ok,
            'detail' => $detail,
            'fix' => $ok ? null : $fix,
        ];
    }
}
