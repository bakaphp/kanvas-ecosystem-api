<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Kanvas\Inventory\Products\Models\Products;
use NeuronAI\Tools\PropertyType as ToolsPropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

class InventorySearchTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'inventory_search',
            description: 'Search for products in the inventory using the search engine (Typesense/Algolia) over name, description and translations. Accepts free-form natural-language queries (e.g. "toyota azul 5 puertas"); the search engine ranks results by relevance even when not all terms map to indexed fields. Returns availability and stock levels.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'product_name',
                type: ToolsPropertyType::STRING,
                description: 'The free-form search query. Can be a product name, keywords, or a natural-language phrase. The search engine matches across name, description and translations.',
                required: true,
            ),
        ];
    }

    public function __invoke(string $product_name): array
    {
        try {
            $products = Products::search($product_name)->take(10)->get();
        } catch (Throwable $e) {
            return ['message' => "Search failed: {$e->getMessage()}"];
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
                'name' => $product->name,
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
}
