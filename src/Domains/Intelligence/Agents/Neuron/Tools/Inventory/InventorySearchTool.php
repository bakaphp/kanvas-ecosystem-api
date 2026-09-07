<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Inventory\Products\Models\Products;
use NeuronAI\Tools\PropertyType as ToolsPropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

#[AgentTool(name: 'Inventory Search', category: 'inventory')]
class InventorySearchTool extends Tool
{
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
        // Products::search() queries `name,description`, where `name` is the
        // translation resolved for a SINGLE locale at index time. A product whose
        // name is only stored under `en` but indexed while another locale was
        // active then can't be found by its English name. `translations.name` /
        // `translations.description` hold EVERY locale joined, so query those too
        // to make the search locale-independent.
        //
        // But a collection created before those fields existed in the schema
        // rejects them ("Could not find a field named `translations.name`"), so
        // fall back to the always-present base fields — search still works
        // pre-reindex, and gains the locale-independent match once reindexed.
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
