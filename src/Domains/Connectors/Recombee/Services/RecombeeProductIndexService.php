<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Services;

use Baka\Contracts\AppInterface;
use Exception;
use InvalidArgumentException;
use Kanvas\Connectors\Recombee\Client;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Services\VariantPriceService;
use Recombee\RecommApi\Client as RecommApiClient;
use Recombee\RecommApi\Requests\AddItemProperty;
use Recombee\RecommApi\Requests\ListItemProperties;
use Recombee\RecommApi\Requests\SetItemValues;

class RecombeeProductIndexService
{
    protected RecommApiClient $client;

    public function __construct(
        protected AppInterface $app,
        string $recombeeDatabase = 'products',
        ?string $recombeeApiKey = null,
        string $recombeeRegion = 'ca-east'
    ) {
        $this->client = (new Client(
            $app,
            $recombeeDatabase,
            $recombeeApiKey,
            $recombeeRegion
        ))->getClient();
    }

    public function createProductCatalogDatabase(): void
    {
        $properties = [
            'title' => 'string',
            'description' => 'string',
            'short_description' => 'string',
            'image_url' => 'image',
            'available' => 'boolean',
            'categories' => 'set',
            'price' => 'double',
            'brand' => 'string',
            'on_sale' => 'boolean',
            'sku' => 'string',
            'product_type' => 'string',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
            'is_published' => 'boolean',
            'warehouse_quantity' => 'int',
            'variants_count' => 'int',
        ];
        $existingProperties = $this->client->send(new ListItemProperties());
        $existingPropertyNames = array_column($existingProperties, 'name');

        foreach ($properties as $property => $type) {
            if (! in_array($property, $existingPropertyNames)) {
                $this->client->send(new AddItemProperty($property, $type));
            }
        }
    }

    /**
     * Index a product to Recombee for recommendations.
     *
     * This method indexes a product with all necessary properties for the Recombee
     * recommendation engine, including master product properties and handling of variants.
     * The master product is indexed with aggregated pricing and availability data.
     */
    public function indexProduct(Products $product): mixed
    {
        if (! $product->is_published) {
            throw new InvalidArgumentException('Only published products can be indexed.');
        }

        $imageUrl = $this->getProductImageUrl($product);
        $firstVariant = $product->variants->first();
        $price = $this->getVariantPrice($firstVariant);
        $available = $this->isProductAvailable($product);
        $totalQuantity = $this->getTotalWarehouseQuantity($product);
        $onSale = $this->isProductOnSale($firstVariant);

        $request = new SetItemValues(
            (string) $product->getId(),
            [
                'title' => $product->name,
                'description' => $product->description,
                'short_description' => $product->short_description,
                'image_url' => $imageUrl,
                'available' => $available,
                'categories' => $product->categories->pluck('slug')->toArray(),
                'price' => $price,
                'brand' => $product->company->name,
                'on_sale' => $onSale,
                'sku' => $firstVariant?->sku,
                'product_type' => $product->productsType?->slug,
                'created_at' => (int) strtotime($product->created_at->toDateTimeString()),
                'updated_at' => (int) strtotime($product->updated_at->toDateTimeString()),
                'is_published' => $product->is_published,
                'warehouse_quantity' => $totalQuantity,
                'variants_count' => $product->variants->count(),
            ],
            ['cascadeCreate' => true]
        );

        return $this->client->send($request);
    }

    /**
     * Index a variant to Recombee.
     *
     * In most cases, variants are represented by the master product. However, this method
     * is available for scenarios where each variant needs to be indexed separately.
     */
    public function indexVariant(Variants $variant): mixed
    {
        if (! $variant->product || ! $variant->product->is_published) {
            throw new InvalidArgumentException('Only variants of published products can be indexed.');
        }

        $product = $variant->product;
        $imageUrl = $this->getVariantImageUrl($variant) ?? $this->getProductImageUrl($product);
        $price = $this->getVariantPrice($variant);
        $available = $this->isVariantAvailable($variant);
        $onSale = $this->isProductOnSale($variant);

        $request = new SetItemValues(
            'variant_' . $variant->getId(),
            [
                'title' => $variant->name,
                'description' => $variant->description,
                'short_description' => $variant->short_description,
                'image_url' => $imageUrl,
                'available' => $available,
                'categories' => $product->categories->pluck('slug')->toArray(),
                'price' => $price,
                'brand' => $product->company->name,
                'on_sale' => $onSale,
                'sku' => $variant->sku,
                'product_type' => $product->productsType?->slug,
                'created_at' => (int) strtotime($variant->created_at->toDateTimeString()),
                'updated_at' => (int) strtotime($variant->updated_at->toDateTimeString()),
                'is_published' => $variant->is_published,
                'warehouse_quantity' => $this->getTotalVariantQuantity($variant),
                'variants_count' => 1,
            ],
            ['cascadeCreate' => true]
        );

        return $this->client->send($request);
    }

    private function getProductImageUrl(Products $product): ?string
    {
        $files = $product->getFiles()->first();

        return $files?->url;
    }

    private function getVariantImageUrl(Variants $variant): ?string
    {
        $files = $variant->getFiles()->first();

        return $files?->url;
    }

    private function isProductAvailable(Products $product): bool
    {
        return $this->getTotalWarehouseQuantity($product) > 0;
    }

    private function isVariantAvailable(Variants $variant): bool
    {
        return $this->getTotalVariantQuantity($variant) > 0;
    }

    private function getTotalWarehouseQuantity(Products $product): int
    {
        return (int) $product->variants()
            ->join('products_variants_warehouses', 'products_variants.id', '=', 'products_variants_warehouses.products_variants_id')
            ->sum('products_variants_warehouses.quantity');
    }

    private function getTotalVariantQuantity(Variants $variant): int
    {
        return (int) $variant->variantWarehouses()
            ->sum('quantity');
    }

    private function isProductOnSale(?Variants $variant = null): bool
    {
        if (! $variant) {
            return false;
        }

        // Check if variant has a discounted price
        $discountedPrice = $variant->variantWarehouses()
            ->whereNotNull('price')
            ->first()?->price;

        return (bool) $discountedPrice;
    }

    private function getVariantPrice(?Variants $variant = null): float
    {
        if (! $variant) {
            return 0;
        }

        try {
            $priceService = new VariantPriceService($this->app);

            return $priceService->getPrice($variant);
        } catch (Exception) {
            // Fallback to first warehouse price
            return (float) ($variant->variantWarehouses()->first()?->price ?? 0);
        }
    }
}
