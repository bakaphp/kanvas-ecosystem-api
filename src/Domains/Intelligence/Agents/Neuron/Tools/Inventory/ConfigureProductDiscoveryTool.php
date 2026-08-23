<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum;
use Kanvas\Inventory\Recommendations\Enums\SemanticProfileStrategyEnum;
use Kanvas\Inventory\Recommendations\Services\ProductDiscoveryStatusService;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Writes only the settings that are safe to set from a conversation.
 *
 * It deliberately does NOT create the collection, run the backfill or reindex:
 * those cost money and hours, and the embedding switch is one-way in practice —
 * a collection already built without the embed field has to be dropped. So this
 * sets the knobs, then reports what a human still has to run.
 */
#[AgentTool(name: 'Configure Product Discovery', category: 'inventory')]
class ConfigureProductDiscoveryTool extends Tool
{
    use GuardsAdminForTool;
    use HasKanvasContext;

    private const string DEFAULT_EMBEDDING_MODEL = 'ts/multilingual-e5-small';

    public function __construct()
    {
        parent::__construct(
            name: 'configure_product_discovery',
            description: 'Set up natural-language product discovery for this app. Admin only. Points '
                . 'products at Typesense, gives the app its own collection, and turns on embeddings so a '
                . 'shopper can search in one language against a catalog written in another. '
                . 'It only writes settings — it does NOT index, enrich or create the collection, because '
                . 'those cost money and take hours. It returns the commands a human still has to run, in '
                . 'order. Run check_product_discovery_setup first to see what is actually missing, and '
                . 'again afterwards to confirm.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'index_name',
                type: PropertyType::STRING,
                description: 'Collection name for this app, e.g. "acme_product_index". Without one the app '
                    . 'shares a collection with every other tenant on the cluster. Defaults to '
                    . '"app{id}_product_index".',
                required: false,
            ),
            new ToolProperty(
                name: 'catalog_type',
                type: PropertyType::STRING,
                description: 'What kind of catalog this is, which decides who the product blurbs are '
                    . 'written for. "gift" = the shopper is buying for someone else, so blurbs describe '
                    . 'who would love to RECEIVE it and for what occasion. "generic" = the shopper is '
                    . 'buying for themselves, so blurbs describe the need it solves. Defaults to '
                    . '"generic". Changing it later means re-running enrichment.',
                required: false,
            ),
            new ToolProperty(
                name: 'embedding_model',
                type: PropertyType::STRING,
                description: 'Embedding model for semantic search. Defaults to "ts/multilingual-e5-small", '
                    . 'which runs inside Typesense and needs no API key. Pass "none" to stay keyword-only.',
                required: false,
            ),
            new ArrayProperty(
                name: 'excluded_categories',
                description: 'Category names never worth recommending, however well they match — gift wrap, '
                    . 'gift cards, shipping fees, warranties. On a gift catalog "Envoltura" scores highly on '
                    . 'every gift query and is never the gift. Names are matched case- and accent-insensitively. '
                    . 'Pass an empty array to clear.',
                required: false,
                items: new ToolProperty(name: 'category', type: PropertyType::STRING, description: 'A category name.'),
            ),
            new ToolProperty(
                name: 'typesense_api_key',
                type: PropertyType::STRING,
                description: 'Typesense API key, if this app needs its own rather than the platform default.',
                required: false,
            ),
            new ToolProperty(
                name: 'typesense_host',
                type: PropertyType::STRING,
                description: 'Typesense host, required only when typesense_api_key is given.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $catalog_type = null,
        ?string $index_name = null,
        ?string $embedding_model = null,
        ?array $excluded_categories = null,
        ?string $typesense_api_key = null,
        ?string $typesense_host = null,
    ): array {
        if ($denied = $this->requireRequestingAdminOrError()) {
            return ['status' => 'error', 'message' => (string) $denied['message']];
        }

        if (! $this->hasTenantContext()) {
            return ['status' => 'error', 'message' => 'This agent has no company context, so it cannot configure discovery.'];
        }

        try {
            $applied = $this->apply(
                $catalog_type,
                $index_name,
                $embedding_model,
                $excluded_categories,
                $typesense_api_key,
                $typesense_host,
            );
            $report = new ProductDiscoveryStatusService($this->app, $this->company)->report();
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'error', 'message' => 'Could not configure discovery: ' . $e->getMessage()];
        }

        return [
            'status' => 'success',
            'applied' => $applied,
            'remaining' => array_values(array_filter(
                array_map(static fn (array $c): ?string => $c['fix'], $report['checks']),
            )),
            'next_commands' => [
                'php artisan kanvas:workflow-sync-actions',
                'php artisan kanvas-inventory:backfill-product-enrichment ' . $this->app->getId() . ' --company_id=' . $this->company->getId() . ' --limit=5 --sync',
                'php artisan kanvas-inventory:backfill-product-enrichment ' . $this->app->getId() . ' --company_id=' . $this->company->getId(),
                'php artisan kanvas-inventory:scout-product-index-process ' . $this->app->getId() . ' --action=reindex',
            ],
            'note' => 'Settings written. Enrich BEFORE reindexing — indexing an un-enriched catalog builds '
                . 'vectors from product names alone and the results look broken. If the collection already '
                . 'exists without an embedding field it must be dropped and rebuilt; the embed field is '
                . 'fixed when the collection is created. Then add a workflow rule on Products '
                . 'created + updated so new products stay enriched. Changing catalog_type invalidates every '
                . 'existing blurb on its own — re-running the backfill rewrites them, no hash clearing needed.',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function apply(
        ?string $catalogType,
        ?string $indexName,
        ?string $embeddingModel,
        ?array $excludedCategories,
        ?string $apiKey,
        ?string $host,
    ): array {
        $applied = [];

        $strategy = SemanticProfileStrategyEnum::fromApp(trim((string) $catalogType) ?: null);
        $this->app->set(ConfigurationEnum::SEMANTIC_PROFILE_STRATEGY->value, $strategy->value);
        $applied[ConfigurationEnum::SEMANTIC_PROFILE_STRATEGY->value] = $strategy->value;

        $this->app->set('products_search_engine', 'typesense');
        $applied['products_search_engine'] = 'typesense';

        $indexName = trim((string) $indexName) ?: 'app' . $this->app->getId() . '_product_index';
        $this->app->set('app_custom_product_index', $indexName);
        $applied['app_custom_product_index'] = $indexName;

        $embeddingModel = trim((string) $embeddingModel) ?: self::DEFAULT_EMBEDDING_MODEL;

        if (mb_strtolower($embeddingModel) !== 'none') {
            $this->app->set(ConfigurationEnum::EMBEDDING_MODEL->value, $embeddingModel);
            // Only meaningful once the collection declares the field; naming it
            // earlier makes Typesense reject every search.
            $this->app->set(ConfigurationEnum::TYPESENSE_QUERY_BY->value, 'search_blurb,name,description,embedding');
            $applied[ConfigurationEnum::EMBEDDING_MODEL->value] = $embeddingModel;
            $applied[ConfigurationEnum::TYPESENSE_QUERY_BY->value] = 'search_blurb,name,description,embedding';
        } else {
            $this->app->set(ConfigurationEnum::TYPESENSE_QUERY_BY->value, 'search_blurb,name,description');
            $applied[ConfigurationEnum::TYPESENSE_QUERY_BY->value] = 'search_blurb,name,description (keyword only)';
        }

        if ($excludedCategories !== null) {
            $names = array_values(array_filter(array_map('trim', array_filter($excludedCategories, 'is_string'))));

            $this->app->set(ConfigurationEnum::EXCLUDED_CATEGORIES->value, $names);
            $applied[ConfigurationEnum::EXCLUDED_CATEGORIES->value] = $names === [] ? 'cleared' : implode(', ', $names);
        }

        if ($apiKey !== null && trim($apiKey) !== '' && $host !== null && trim($host) !== '') {
            $this->app->set('typesense_search_settings', [
                'typesense_api_key' => trim($apiKey),
                'typesense_nodes' => [[
                    'host' => trim($host),
                    'port' => 443,
                    'path' => '/',
                    'protocol' => 'https',
                ]],
            ]);
            $applied['typesense_search_settings'] = 'set for host ' . trim($host);
        }

        return $applied;
    }
}
