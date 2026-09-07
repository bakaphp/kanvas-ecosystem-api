<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Inventory\Products\Models\Products;
use NeuronAI\Tools\PropertyType as ToolsPropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

#[AgentTool(name: 'Inventory Search', category: 'inventory')]
class InventorySearchTool extends Tool
{
    // Lets the voice data plane hand this tool the AGENT's tenant
    // (RunVoiceAgentToolAction::withContext), so the search binds the agent's app
    // rather than trusting whatever app(Apps::class) happens to be.
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'inventory_search',
            description: 'Search for products in the inventory using the search engine (Typesense/Algolia) '
                . 'over name, description and translations. Accepts free-form natural-language queries '
                . '(e.g. "toyota azul 5 puertas"); the search engine ranks results by relevance even when '
                . 'not all terms map to indexed fields. Returns availability and stock levels.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'product_name',
                type: ToolsPropertyType::STRING,
                description: 'The free-form search query. Can be a product name, keywords, or a natural-language phrase. '
                    . 'The search engine matches across name, description and translations.',
                required: true,
            ),
        ];
    }

    public function __invoke(string $product_name): array
    {
        // Bind the search to the AGENT's app. Products::search() reads the ambient
        // app(Apps::class) for both the Typesense index and the apps_id filter;
        // the voice runtime authenticates as its own app-key app, so without this
        // the tool searches the WRONG tenant's catalogue. withContext() (set by
        // RunVoiceAgentToolAction) hands us the agent's app; bind it explicitly so
        // the search can't drift to the runtime's app. Restored by the caller.
        if ($this->hasTenantContext()) {
            app()->instance(Apps::class, $this->app);
        }

        // query_by is widened to the all-locales `translations.*` fields so a
        // product is found by its English name even when the indexed `name` was
        // resolved under another locale. A collection created before those fields
        // existed rejects them ("Could not find a field named `translations.name`"),
        // so fall back to the always-present base fields — search still works
        // pre-reindex and gains the locale-independent match once reindexed.
        try {
            $products = $this->searchProducts($product_name, 'name,description,translations.name,translations.description');
        } catch (Throwable $e) {
            if (! str_contains($e->getMessage(), 'Could not find a field named')) {
                return ['message' => "Search failed: {$e->getMessage()}"];
            }
            try {
                $products = $this->searchProducts($product_name, 'name,description');
            } catch (Throwable $retry) {
                return ['message' => "Search failed: {$retry->getMessage()}"];
            }
        }

        if ($products->isEmpty()) {
            return ['message' => "No products found matching '{$product_name}'."];
        }

        $products->load('variants');

        return $products->map(function (Products $product) {
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

    /**
     * Run the Scout search with an explicit Typesense `query_by`, overriding the
     * value Products::search() sets. Split out so __invoke can retry with a
     * narrower field set when the collection's schema lacks the wider ones.
     *
     * @return \Illuminate\Support\Collection<int, Products>
     */
    private function searchProducts(string $query, string $queryBy): \Illuminate\Support\Collection
    {
        return Products::search($query)
            ->options(['query_by' => $queryBy])
            ->take(10)
            ->get();
    }
}
