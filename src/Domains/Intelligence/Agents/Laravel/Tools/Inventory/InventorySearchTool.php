<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Inventory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Inventory\Products\Models\Products;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;
use Throwable;

class InventorySearchTool implements Tool
{
    #[Override]
    public function description(): Stringable|string
    {
        return 'Search for products in the inventory by name and check their availability and stock levels.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $query = $request->string('product_name');

        $products = Products::fromApp()
            ->fromCompany()
            ->notDeleted()
            ->where('name', 'like', '%' . $query . '%')
            ->with('variants')
            ->limit(10)
            ->get();

        if ($products->isEmpty()) {
            return "No products found matching '{$query}'.";
        }

        $results = $products->map(function (Products $product) {
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
                        'name' => $variant->name,
                        'sku' => $variant->sku,
                        'stock' => $variant->getTotalQuantity(),
                        'price' => $price,
                    ];
                })->toArray(),
            ];
        });

        return $results->toJson(JSON_PRETTY_PRINT);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'product_name' => $schema
                ->string()
                ->description('The product name or keyword to search for in the inventory.')
                ->required(),
        ];
    }
}
