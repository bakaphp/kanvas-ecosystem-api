<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Illuminate\Database\Eloquent\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\Concerns\RespondsWithNoInventoryMatches;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Souk\Services\StorefrontProductUrlService;
use Laravel\Scout\Builder;
use NeuronAI\Tools\PropertyType as ToolsPropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

#[AgentTool(name: 'Inventory Search', category: 'inventory', readOnly: true)]
class InventorySearchTool extends Tool
{
    // Lets the voice data plane hand this tool the AGENT's tenant
    // (RunVoiceAgentToolAction::withContext), so the search binds the agent's app
    // rather than trusting whatever app(Apps::class) happens to be.
    use HasKanvasContext;
    use RespondsWithNoInventoryMatches;
    use TrackByInputs;

    private const string QUERY_BY_ALL_LOCALES = 'name,description,translations.name,translations.description';
    private const string QUERY_BY_BASE = 'name,description';
    private const string TENANT_FIELD = 'companies_id';
    private const string LEGACY_TENANT_FIELD = 'company.id';

    protected string $name = 'inventory_search';

    protected ?string $description = 'Search for products in the inventory using the search engine (Typesense/Algolia) '
        . 'over name, description and translations. Accepts free-form natural-language queries '
        . '(e.g. "toyota azul 5 puertas"). Pass the customer\'s complete inventory request verbatim, '
        . 'in their original language. Never paraphrase, summarize, translate, extract only keywords, '
        . 'or replace the request with an inferred make, model, body style, or product name. Preserve '
        . 'every stated preference and constraint in the query; the search engine must interpret them. '
        . 'The search engine ranks results by relevance even when '
        . 'not all terms map to indexed fields. Searches only within the company bound to the agent context. '
        . 'Returns availability and stock levels. A no_matches result means only that this search found no '
        . 'matching indexed inventory; it does not confirm dealership unavailability. When no_matches is '
        . 'returned, follow the RAG business rule "No Matching Inventory Results — STRICT HANDOFF RULE".';

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'product_name',
                type: ToolsPropertyType::STRING,
                description: 'The customer\'s complete inventory request, copied verbatim in their original language. '
                    . 'Do not paraphrase, summarize, translate, extract keywords, or substitute an inferred vehicle. '
                    . 'Include every preference and constraint the customer stated. The search engine matches across '
                    . 'name, description and translations.',
                required: true,
            ),
        ];
    }

    public function __invoke(string $product_name): array
    {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('inventory search');
        }

        // Bind the search to the AGENT's app. Products::search() reads the ambient
        // app(Apps::class) for both the Typesense index and the apps_id filter;
        // the voice runtime authenticates as its own app-key app, so without this
        // the tool searches the WRONG tenant's catalogue. withContext() (set by
        // RunVoiceAgentToolAction) hands us the agent's app; bind it explicitly so
        // the search can't drift to the runtime's app.
        app()->instance(Apps::class, $this->app);

        try {
            $products = $this->searchWithSchemaFallbacks($product_name);
        } catch (Throwable $e) {
            // The engine's message names index fields and filters — log it, never speak it.
            report($e);

            return $this->searchUnavailableResponse('inventory');
        }

        // Defense in depth: whichever tenant filter the engine applied, only this
        // company's products leave the tool.
        $companyId = (int) $this->company->getId();
        $products = $products
            ->filter(fn (Products $product) => (int) $product->companies_id === $companyId)
            ->values();

        if ($products->isEmpty()) {
            return $this->noMatchesResponse($product_name);
        }

        $products->load('variants');
        $storefront = new StorefrontProductUrlService($this->company, $this->app);

        return $products->map(function (Products $product) use ($storefront) {
            $variants = $product->variants;
            $totalStock = $variants->sum(fn ($variant) => $variant->getTotalQuantity());
            $isAvailable = $totalStock > 0 && $product->is_published;

            return [
                'id' => $product->getId(),
                // Prefer the English name so the agent speaks a stable, readable
                // label regardless of the call's locale; fall back to the resolved
                // accessor when no `en` translation exists.
                'name' => $product->getTranslation('name', 'en') ?: $product->name,
                'slug' => $product->slug,
                'url' => $storefront->productUrl($product),
                'is_published' => (bool) $product->is_published,
                'is_available' => $isAvailable,
                'total_stock' => $totalStock,
                'variants' => $variants->map(function ($variant) {
                    try {
                        $price = $variant->getPriceInfoFromDefaultChannel()->price ?? null;
                    } catch (Throwable) {
                        $price = null;
                    }

                    return [
                        'id' => $variant->getId(),
                        'name' => $variant->name,
                        'sku' => $variant->sku,
                        'stock' => $variant->getTotalQuantity(),
                        'price' => $price,
                    ];
                })->toArray(),
            ];
        })->toArray();
    }

    protected function searchQuery(
        string $productName,
        string $queryBy = self::QUERY_BY_ALL_LOCALES,
        string $tenantField = self::TENANT_FIELD,
    ): Builder {
        $query = Products::search($productName);

        if ($this->hasTenantContext()) {
            // Scout translates this where into Algolia `filters` or Typesense `filter_by`.
            // `companies_id` is the flat, filterable tenant field shared by Algolia and
            // Typesense. Enforce it from the agent context instead of auth state or app flags.
            // `company.id` (the nested object) is the legacy equivalent for collections
            // created before `companies_id` was declared — see searchWithSchemaFallbacks().
            $query->where($tenantField, $this->company->getId());
        }

        // Products::search() queries `name,description`, where `name` is the
        // translation resolved for a SINGLE locale at index time. A product
        // whose name is only stored under `en` but indexed while another
        // locale was active then can't be found by its English name. The
        // `translations.name` / `translations.description` fields hold EVERY
        // locale joined, so query those too to make the search
        // locale-independent. Typesense-only; other engines ignore the extra
        // fields. This overrides the query_by set inside Products::search().
        if ($query->model->isTypesense()) {
            $query->options(['query_by' => $queryBy]);
        }

        return $query;
    }

    /**
     * Run the search, stepping down to fields an older Typesense collection has.
     *
     * A collection is created once from Products::typesenseCollectionSchema() and
     * never revisited by Scout, so one born before a field was declared rejects
     * any query naming it (TypesenseSchemaReconciler explains the general case):
     *  - `translations.*` in query_by → "Could not find a field named …": retry on
     *    the always-present `name,description`.
     *  - `companies_id` in filter_by → "Could not find a filter field named
     *    `companies_id`": retry filtering on the nested `company.id`, which every
     *    product collection indexes (enable_nested_fields) and Products::search()
     *    already filters on. The tenant filter is swapped, never dropped; the caller
     *    also re-checks companies_id on every returned row.
     * Each fallback applies at most once; any other failure is rethrown.
     *
     * @return Collection<int, Products>
     */
    protected function searchWithSchemaFallbacks(string $productName)
    {
        $queryBy = self::QUERY_BY_ALL_LOCALES;
        $tenantField = self::TENANT_FIELD;

        while (true) {
            try {
                return $this->searchQuery($productName, $queryBy, $tenantField)
                    ->take(10)
                    ->get();
            } catch (Throwable $e) {
                $message = $e->getMessage();
                $collectionLacksTenantField = $tenantField === self::TENANT_FIELD
                    && str_contains($message, 'filter field named `' . self::TENANT_FIELD . '`');
                $collectionLacksLocaleFields = $queryBy === self::QUERY_BY_ALL_LOCALES
                    && str_contains($message, 'Could not find a field named');

                if ($collectionLacksTenantField) {
                    $tenantField = self::LEGACY_TENANT_FIELD;

                    continue;
                }

                if ($collectionLacksLocaleFields) {
                    $queryBy = self::QUERY_BY_BASE;

                    continue;
                }

                throw $e;
            }
        }
    }
}
