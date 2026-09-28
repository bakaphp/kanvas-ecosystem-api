<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Baka\Support\Str;
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
            new ToolProperty(
                name: 'catalog_language',
                type: PropertyType::STRING,
                description: 'Two-letter language the shoppers actually type, e.g. "es". Shipped term '
                    . 'matching is English ONLY, so on a Spanish storefront "de lujo", "barato" and '
                    . '"para mi novia" parse as nothing until this is set — budget filters and recipient '
                    . 'filtering are both silently dead. Adds that language\'s terms alongside the '
                    . 'English ones rather than replacing them. Omit for an English catalog.',
                required: false,
            ),
            new ArrayProperty(
                name: 'excluded_categories',
                description: 'Category names that are NEVER the answer, however well they match — gift '
                    . 'wrap, shipping fees, warranties, installation. These are transaction mechanics, not '
                    . 'products anyone wants. Do NOT list something that is merely wrong for one shopper: a '
                    . 'gift card IS a gift, it was only wrong for a man, and excluding it would break the '
                    . 'query where it belongs — that is what recipient filtering is for. This is a hard drop '
                    . 'with no fallback, so a shopper searching the category itself gets nothing. Matched '
                    . 'case- and accent-insensitively. Empty array clears.',
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
        ?string $catalog_language = null,
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
                $catalog_language,
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
            'gotchas' => [
                'queue' => 'The backfill without --sync only DISPATCHES to the `product-enrichment` queue. '
                    . 'It reports every product processed either way, so with no worker consuming that queue '
                    . 'nothing is enriched and the count is a lie. Same for `scout` when SCOUT_QUEUE=true: '
                    . 'the collection silently stays stale. Check both workers are up, or use --sync.',
                'schema' => 'Reindexing does NOT add a field to a collection that already exists — Scout '
                    . 'creates collections but never migrates them. A collection built before a field '
                    . 'existed will never gain it by reindexing; PATCH /collections/{name} with the field, '
                    . 'or drop and rebuild. Run check_product_discovery_setup, which reports this.',
                'config' => 'config/inventory-discovery.php is cached in every container at boot. New '
                    . 'shipped terms need a config cache refresh and an Octane restart before they load.',
                'order' => 'Enrich BEFORE reindexing. Indexing an un-enriched catalog builds vectors from '
                    . 'product names alone, and the results look broken rather than empty.',
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
        ?string $catalogLanguage,
        ?string $indexName,
        ?string $embeddingModel,
        ?array $excludedCategories,
        ?string $apiKey,
        ?string $host,
    ): array {
        $applied = [];

        $strategy = SemanticProfileStrategyEnum::fromApp(Str::trimToNull((string) $catalogType));
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

        $language = mb_strtolower(trim((string) $catalogLanguage));
        $terms = (array) config('inventory-discovery.intent_lexicon_translations.' . $language, []);

        if ($terms !== []) {
            // Merged over whatever is already there so a hand-tuned bucket survives.
            $this->app->set(
                ConfigurationEnum::INTENT_LEXICON->value,
                [...ConfigurationEnum::INTENT_LEXICON->listFrom($this->app), ...$terms],
            );
            $applied[ConfigurationEnum::INTENT_LEXICON->value] = $language . ' terms (' . count($terms) . ' buckets)';
        } elseif ($language !== '') {
            $applied[ConfigurationEnum::INTENT_LEXICON->value] = "no shipped terms for '{$language}' — add them by hand";
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
