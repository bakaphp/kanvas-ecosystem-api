<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Inventory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Inventory\Variants\Models\Variants;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class VariantSearchTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Search product variants by name or SKU. Returns variant details including SKU, price, stock, and its parent product name. Use this when the user asks about a specific SKU or variant name.';
    }

    public function handle(Request $request): Stringable|string
    {
        $keyword = $request->string('keyword');

        if ($keyword === '') {
            return 'Please provide a keyword (name or SKU) to search for variants.';
        }

        $variants = Variants::fromApp()
            ->fromCompany()
            ->notDeleted()
            ->where(function ($q) use ($keyword) {
                $q->where('name', 'like', '%' . $keyword . '%')
                    ->orWhere('sku', 'like', '%' . $keyword . '%');
            })
            ->with('product')
            ->limit(20)
            ->get();

        if ($variants->isEmpty()) {
            return "No variants found matching '{$keyword}'.";
        }

        $results = $variants->map(fn (Variants $variant) => [
            'id' => $variant->getId(),
            'name' => $variant->name,
            'sku' => $variant->sku,
            'product' => $variant->product?->name,
            'is_published' => (bool) $variant->is_published,
            'stock' => $variant->getTotalQuantity(),
        ]);

        return $results->toJson(JSON_PRETTY_PRINT);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'keyword' => $schema
                ->string()
                ->description('Name or SKU to search for. Partial matches are supported.')
                ->required(),
        ];
    }
}
