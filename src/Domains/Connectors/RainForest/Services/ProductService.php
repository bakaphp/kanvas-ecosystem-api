<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RainForest\Services;

use Illuminate\Support\Str;
use Kanvas\Connectors\RainForest\Enums\ConfigurationEnum as RainForestConfigurationEnum;
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
        $price = (float)$product['price']['value'];
        $discountPrice = $price - ($price * 0.1);

        return [
            'name' => $product['title'],
            'description' => $product['title'],
            'price' => $discountPrice,
            'discountPrice' => $discountPrice,
            'slug' => Str::slug($product['title']),
            'sku' => $product['asin'],
            'source_id' => $product['asin'],
            'files' => $this->mapFilesystem($product),
            'attributes' => $this->mapAttributes($product),
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
            'variants' => $this->mapVariants($product),
            'custom_fields' => [
                [
                    'name' => RainForestConfigurationEnum::AMAZON_ID->value,
                    'data' => $product['asin'],
                ],
                [
                    'name' => ConfigurationEnum::WEIGHT_UNIT->value,
                    'data' => $this->calcWeight($product),
                ],
            ],
        ];
    }

    protected function mapFilesystem(array $product): array
    {
        $files = [
            [
                'url' => $product['main_image']['link'],
                'name' => 'main_image',
            ],
        ];

        foreach ($product['images'] as $image) {
            $files[] = [
                'url' => $image['link'],
                'name' => $image['variant'],
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
        $categories = [];
        $position = 1;
        foreach ($product['categories'] as $category) {
            $categories[] = [
                'name' => $category['name'],
                'source_id' => isset($category['category_id']) ? $category['category_id'] : null,
                'isPublished' => true,
                'position' => $position,
                'code' => isset($category['category_id']) ? $category['category_id'] : null,
            ];
            $position++;
        }

        return $categories;
    }

    public function mapVariants(array $product): array
    {
        // To do: Some products have variants, some don't.
        // Need to handle both cases.
        $variants = [];
        if (! $product['variants']) {
            $price = (float)$product['price']['value'];
            $discountPrice = $price - ($price * 0.1);

            $variants[] = [
                'name' => $product['title'],
                'description' => $product['title'],
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
        // foreach ($product['variants'] as $variant) {
        //     $variants[] = [
        //         'name' => $variant['name'],
        //         'price' => (float)$variant['price']['value'],
        //         'discountPrice' => (float)$variant['price']['value'] - ((float)$variant['price']['value'] * 0.1),
        //         'quantity' => 0,
        //         'sku' => $variant['asin'],
        //         'source_id' => $variant['asin'],
        //         'attributes' => $this->mapAttributes($variant),

        //     ];
        // }

        return $variants;
    }

    public function calcWeight(array $product): float
    {
        $weight = $productDetail['shipping_weight'] ?? 0;
        if ($weight && str_contains($weight, 'ounces')) {
            $weight = (float) str_replace('ounces', '', $weight) * 28.3495;
        } elseif ($weight && str_contains($weight, 'pounds')) {
            $weight = (float) str_replace('pounds', '', $weight) * 453.592;
        }

        return $weight;
    }
}
