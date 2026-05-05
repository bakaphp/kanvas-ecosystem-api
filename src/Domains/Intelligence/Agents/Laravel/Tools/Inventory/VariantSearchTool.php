<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Inventory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Enums\ConfigurationEnum as SoukConfigurationEnum;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

class VariantSearchTool implements Tool
{
    #[Override]
    public function description(): Stringable|string
    {
        return 'Search product variants by name or SKU. Returns variant details including SKU, price, stock, and its parent product name. Use this when the user asks about a specific SKU or variant name.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $keyword = (string) $request->string('keyword');

        if ($keyword === '') {
            return 'Please provide a keyword (name or SKU) to search for variants.';
        }

        $allowCrossCompany = (bool) app(Apps::class)->get(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value);

        $builder = Variants::fromApp()
            ->notDeleted()
            ->where(function ($q) use ($keyword) {
                $q->where('name', 'like', '%' . $keyword . '%')
                    ->orWhere('sku', 'like', '%' . $keyword . '%');
            })
            ->with('product')
            ->limit(20);

        if (! $allowCrossCompany) {
            $builder->fromCompany();
        }

        $variants = $builder->get();

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

    #[Override]
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
