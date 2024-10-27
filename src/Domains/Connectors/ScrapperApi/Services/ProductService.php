<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Services;

use Illuminate\Support\Str;
use Kanvas\Connectors\ScrapperApi\Enums\ConfigEnum as ScrapperConfigEnum;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Variants\Enums\ConfigurationEnum;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

class ProductService
{
    public function __construct(
        private Channels $channels,
        private Warehouses $warehouse
    ) {
    }

    public function mapProduct(array $product): array
    {
        if (key_exists('original_price', $product)) {
            $price = (float)$product['original_price']['price'];
            $discountPrice = $price - ($price * 0.1);
        } else {
            $price = (float)$product['price'];
            $discountPrice = $price - ($price * 0.1);
        }

        $product = [
            'name' => $product['name'],
            'description' => $product['full_description'],
            'price' => $discountPrice,
            'discountPrice' => $discountPrice,
            'slug' => Str::slug($product['name']),
            'sku' => $product['asin'],
            'source_id' => $product['asin'],
            'files' => $this->mapFilesystem(product: ['image' => $product['image'],'images' => $product['images']]),
            'quantity' => 0,
            'isPublished' => true,
            'categories' => $this->mapCategories($product),
            'warehouses' => [
                [
                    'id' => $this->warehouse->id,
                    'price' => (float) $discountPrice,
                    'warehouse' => $this->warehouse->name,
                    'quantity' => 0,
                    'sku' => $product['asin'],
                    'is_new' => true,
                    'channel' => $this->channels->name,
                ],
            ],
            'custom_fields' => [
                [
                    'name' => ScrapperConfigEnum::AMAZON_ID->value,
                    'data' => $product['asin'],
                ],
                [
                    'name' => ConfigurationEnum::WEIGHT_UNIT->value,
                    'data' => $this->calcWeight($product),
                ],
            ],
        ];
        $product['variants'][] = $product;

        return $product;
    }

    protected function mapFilesystem(array $product): array
    {
        $files = [
            [
                'url' => $product['image'],
                'name' => 'main_image',
            ],
        ];

        foreach ($product['images'] as $image) {
            $files[] = [
                'url' => $image,
                'name' => basename($image),
            ];
        }

        return $files;
    }

    public function mapAttributes(array $product): array
    {
        $attributes = [];
        if (! key_exists('attributes', $product)) {
            return $attributes;
        }
        foreach ($product['attributes'] as $attribute) {
            $attributes[] = [
                'name' => $attribute['name'],
                'value' => $attribute['value'],
            ];
        }

        return $attributes;
    }

    public function mapCategories(array $product): array
    {
        $categories = explode('>', $product['product_category']);
        $mapCategories = [];
        $position = 1;
        foreach ($categories as $category) {
            $mapCategories[] = [
                'name' => $category,
                'source_id' => null,
                'isPublished' => true,
                'position' => $position,
                'code' => null,
            ];
            $position++;
        }

        return $mapCategories;
    }

    public function mapVariants(array $product, float $price, float $discountPrice): array
    {
        // To do: Some products have variants, some don't.
        // Need to handle both cases.
        return [
            'name' => $product['name'],
            'description' => $product['full_description'],
            'sku' => $product['asin'],
            'price' => $discountPrice,
            'discountPrice' => $discountPrice,
            'is_published' => true,
            'source_id' => (string) $product['asin'],
            'slug' => (string) $product['asin'],
            'files' => $this->mapFilesystem($product),
            'warehouses' => [
                [
                    'id' => $this->warehouse->id,
                    'price' => (float) $discountPrice,
                    'quantity' => 0,
                    'sku' => $product['asin'],
                    'is_new' => true,
                ],
            ],
            'attributes' => [
            ],
            'custom_fields' => [
                [
                    'name' => 'AMAZON_ID',
                    'data' => $product['asin'],
                ],
                [
                    'name' => ConfigurationEnum::WEIGHT_UNIT->value,
                    'data' => $this->calcWeight($product),
                ],
            ],
        ];
    }

    public function calcWeight(array $product): float
    {
        $weight = $product['product_information']['item_weight'] ?? 0;
        if ($weight && str_contains($weight, 'ounces')) {
            $weight = Str::before($weight, 'ounces') * 28.3495;
        } elseif ($weight && str_contains($weight, 'pounds')) {
            $weight = Str::before($weight, 'pounds') * 453.592;
        }

        return $weight;
    }
}
