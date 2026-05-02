<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Inventory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Inventory\Products\Models\Products;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

class ListAvailableProductsTool implements Tool
{
    #[Override]
    public function description(): Stringable|string
    {
        return 'List products from the inventory filtered by published status and stock availability. '
            . 'Use is_published=true for published products, is_published=false for unpublished/draft products. '
            . 'Use only_in_stock=true to filter only products with stock available.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $limit = min($request->integer('limit', 20), 50);
        $isPublished = $request->boolean('is_published', true);
        $onlyInStock = $request->boolean('only_in_stock', false);

        $products = Products::fromApp()
            ->fromCompany()
            ->notDeleted()
            ->where('is_published', $isPublished ? 1 : 0)
            ->with('variants')
            ->limit($limit)
            ->get();

        $label = $isPublished ? 'published' : 'unpublished';

        if ($products->isEmpty()) {
            return "No {$label} products found in the inventory.";
        }

        $results = $products->map(function (Products $product) {
            $variants = $product->variants;
            $totalStock = $variants->sum(fn ($variant) => $variant->getTotalQuantity());

            return [
                'id' => $product->getId(),
                'name' => $product->name,
                'slug' => $product->slug,
                'is_published' => (bool) $product->is_published,
                'total_stock' => $totalStock,
                'variants' => $variants->map(fn ($variant) => [
                    'name' => $variant->name,
                    'sku' => $variant->sku,
                    'stock' => $variant->getTotalQuantity(),
                ])->toArray(),
            ];
        });

        if ($onlyInStock) {
            $results = $results->filter(fn ($product) => $product['total_stock'] > 0);

            if ($results->isEmpty()) {
                return "No {$label} products with stock found.";
            }
        }

        return $results->values()->toJson(JSON_PRETTY_PRINT);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'is_published' => $schema
                ->boolean()
                ->description('Filter by published status. true = published, false = unpublished/draft. Defaults to true.')
                ->default(true),
            'only_in_stock' => $schema
                ->boolean()
                ->description('When true, only returns products with stock > 0. Defaults to false.')
                ->default(false),
            'limit' => $schema
                ->integer()
                ->description('Maximum number of products to return. Defaults to 20, max 50.')
                ->default(20),
        ];
    }
}
