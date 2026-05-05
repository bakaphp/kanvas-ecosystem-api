<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Inventory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;
use Throwable;

class ProductRecommendationLookupTool implements Tool
{
    #[Override]
    public function description(): Stringable|string
    {
        return 'Look up rich inventory data shaped for product recommendations. '
            . 'Returns each product with its files, categories, and full variant data '
            . '(id, slug, sku, attributes, channel pricing, files). '
            . 'Call this tool once per distinct interest in a multi-interest request '
            . '(e.g. "cooking", "clothes", "perfumes" → 3 calls), passing min_price/max_price for budget.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $keyword = (string) $request->string('keyword');
        $limit = min(max($request->integer('limit', 5), 1), 20);
        $onlyInStock = $request->boolean('only_in_stock', true);
        $minPrice = $request->has('min_price') ? (float) $request->float('min_price') : null;
        $maxPrice = $request->has('max_price') ? (float) $request->float('max_price') : null;

        $query = Products::fromApp()
            ->fromCompany()
            ->notDeleted()
            ->where('is_published', 1)
            ->with(['variants', 'categories']);

        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', '%' . $keyword . '%')
                    ->orWhere('description', 'like', '%' . $keyword . '%')
                    ->orWhere('short_description', 'like', '%' . $keyword . '%');
            });
        }

        // Fetch a wider pool when filtering by price so we can drop variants outside the budget.
        $poolSize = ($minPrice !== null || $maxPrice !== null) ? min($limit * 4, 80) : $limit;

        $products = $query->limit($poolSize)->get();

        if ($products->isEmpty()) {
            return $keyword === ''
                ? 'No published products available to recommend.'
                : "No products found matching '{$keyword}'.";
        }

        $results = $products
            ->map(fn (Products $product) => $this->mapProduct($product, $onlyInStock, $minPrice, $maxPrice))
            ->filter()
            ->take($limit)
            ->values();

        if ($results->isEmpty()) {
            $budget = $this->describeBudget($minPrice, $maxPrice);

            return "No in-stock products found matching '{$keyword}'{$budget}.";
        }

        return $results->toJson(JSON_PRETTY_PRINT);
    }

    private function mapProduct(
        Products $product,
        bool $onlyInStock,
        ?float $minPrice,
        ?float $maxPrice,
    ): ?array {
        $variants = $product->variants
            ->map(fn (Variants $variant) => $this->mapVariant($variant))
            ->filter(function (array $v) use ($onlyInStock, $minPrice, $maxPrice) {
                if ($onlyInStock && (($v['channel']['quantity'] ?? 0) <= 0)) {
                    return false;
                }

                $price = $v['channel']['price'] ?? null;

                if ($minPrice !== null && ($price === null || $price < $minPrice)) {
                    return false;
                }

                if ($maxPrice !== null && ($price === null || $price > $maxPrice)) {
                    return false;
                }

                return true;
            })
            ->values();

        if ($variants->isEmpty()) {
            return null;
        }

        return [
            'product' => [
                'id' => $product->getId(),
                'slug' => $product->slug,
                'name' => $product->name,
                'files' => $this->mapFiles($product),
                'categories' => $product->categories
                    ->map(fn ($category) => [
                        'id' => $category->getId(),
                        'name' => $category->name,
                    ])
                    ->all(),
            ],
            'variants' => $variants->all(),
        ];
    }

    private function mapVariant(Variants $variant): array
    {
        return [
            'id' => $variant->getId(),
            'slug' => $variant->slug,
            'name' => $variant->name,
            'sku' => $variant->sku,
            'description' => $variant->description,
            'attributes' => array_map(
                fn (array $a) => [
                    'id' => $a['id'] ?? null,
                    'name' => $a['name'] ?? null,
                    'value' => $this->stringifyValue($a['value'] ?? null),
                ],
                $variant->visibleAttributes(),
            ),
            'channel' => $this->resolveChannel($variant),
            'files' => $this->mapFiles($variant),
        ];
    }

    private function resolveChannel(Variants $variant): array
    {
        try {
            $defaultChannel = Channels::getDefault($variant->company, $variant->app);
        } catch (Throwable) {
            return $this->emptyChannel();
        }

        $channelInfo = $variant->variantChannels()
            ->with('productVariantWarehouse')
            ->where('channels_id', $defaultChannel->getId())
            ->first();

        if (! $channelInfo) {
            return $this->emptyChannel();
        }

        $price = (float) $channelInfo->price;
        $discounted = (float) $channelInfo->discounted_price;

        return [
            'price' => $price,
            'discounted_price' => $discounted,
            'is_on_sale' => $discounted > 0 && $discounted < $price,
            'quantity' => (int) ($channelInfo->productVariantWarehouse?->quantity ?? 0),
        ];
    }

    private function emptyChannel(): array
    {
        return [
            'price' => null,
            'discounted_price' => null,
            'is_on_sale' => false,
            'quantity' => 0,
        ];
    }

    private function mapFiles(Products|Variants $entity): array
    {
        return $entity->getFiles()
            ->take(10)
            ->map(fn ($file) => [
                'id' => $file->getId(),
                'url' => $file->url,
                'name' => $file->name,
            ])
            ->values()
            ->all();
    }

    private function stringifyValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value);
    }

    private function describeBudget(?float $minPrice, ?float $maxPrice): string
    {
        if ($minPrice !== null && $maxPrice !== null) {
            return " within budget {$minPrice} - {$maxPrice}";
        }

        if ($minPrice !== null) {
            return " above {$minPrice}";
        }

        if ($maxPrice !== null) {
            return " below {$maxPrice}";
        }

        return '';
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'keyword' => $schema
                ->string()
                ->description('Product name / interest keyword to filter by, in the original user language (e.g. "cocina", "perfume"). Leave empty for top products.'),
            'min_price' => $schema
                ->number()
                ->description('Minimum variant price (inclusive). Use the user-provided budget.'),
            'max_price' => $schema
                ->number()
                ->description('Maximum variant price (inclusive). Use the user-provided budget.'),
            'limit' => $schema
                ->integer()
                ->description('Maximum number of products to return (1-20). Defaults to 5.')
                ->default(5),
            'only_in_stock' => $schema
                ->boolean()
                ->description('When true, only returns variants with stock > 0. Defaults to true.')
                ->default(true),
        ];
    }
}
