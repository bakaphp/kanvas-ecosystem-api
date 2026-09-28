<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Search\SearchEngineResolver;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\ProductEnrichment\Agents\ProductEnrichmentAgent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum;
use Kanvas\Inventory\Recommendations\Enums\SearchFieldEnum;
use Kanvas\Inventory\Recommendations\Enums\SemanticProfileStrategyEnum;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\RuleWorkflowAction;
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
    ) {
    }

    /**
     * @return array{ready: bool, checks: list<array{key: string, ok: bool, detail: string, fix: ?string}>}
     */
    public function report(): array
    {
        $checks = [
            $this->engineCheck(),
            $this->credentialsCheck(),
            $this->indexNameCheck(),
            $this->collectionCheck(),
            $this->queryByCheck(),
            $this->strategyCheck(),
            $this->enrichmentAgentCheck(),
            $this->blurbCoverageCheck(),
            $this->workflowRuleCheck(),
        ];

        return [
            'ready' => ! in_array(false, array_column($checks, 'ok'), true),
            'checks' => $checks,
        ];
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
        $settings = (array) ($this->app->get('typesense_search_settings') ?? []);
        $hasKey = ($settings['typesense_api_key'] ?? config('scout.typesense.api_key')) !== '';

        if (! $hasKey) {
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
            $collection = SearchEngineResolver::getTypesenseClient(
                (array) ($this->app->get('typesense_search_settings') ?? []),
            )->collections[$name]->retrieve();
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
            $pct >= 90 ? null : 'run kanvas-inventory:backfill-product-enrichment — below full coverage the un-enriched majority outranks the rest',
        );
    }

    private function workflowRuleCheck(): array
    {
        $action = Action::where('model_name', 'like', '%EnrichProductActivity')->first();

        if ($action === null) {
            return $this->check('workflow_rule', false, 'action not registered', 'run kanvas:workflow-sync-actions');
        }

        $wired = RuleWorkflowAction::where('actions_id', $action->getId())->exists();

        return $this->check(
            'workflow_rule',
            $wired,
            $wired ? 'wired' : 'no rule uses it',
            'add a rule on Products created + updated running "' . $action->name . '", or the catalog goes stale after the backfill',
        );
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
