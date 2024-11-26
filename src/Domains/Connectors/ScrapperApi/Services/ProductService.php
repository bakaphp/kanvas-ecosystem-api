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
        $weight = $this->calcWeight($product);
        if (key_exists('original_price', $product)) {
            $price = (float)$product['original_price']['price'];
            $product['price'] = $price;
        }
        $amazonPrice = $product['price'];
        $price = $this->calcDiscountPrice($product);
        $name = Str::limit($product['name'], 255);
        $product = [
            'name' => $name,
            'description' => $this->getDescription($product),
            'price' => $price['total'],
            'discountPrice' => $price['discount'],
            'slug' => Str::slug($product['asin']),
            'sku' => $product['asin'],
            'source_id' => $product['asin'],
            'files' => $this->mapFilesystem(product: ['image' => $product['image'],'images' => $product['images']]),
            'quantity' => $this->channels->app->get(ScrapperConfigEnum::DEFAULT_QUANTITY->value) ?? 1,
            'isPublished' => true,
            'categories' => $this->mapCategories($product),
            'warehouses' => [
                [
                    'id' => $this->warehouse->id,
                    'price' => (float) $price['total'],
                    'warehouse' => $this->warehouse->name,
                    'quantity' => 10,
                    'sku' => $product['asin'],
                    'is_new' => true,
                    'channel' => $this->channels->name,
                ],
            ],
            'attributes' => [
                [
                    'name' => ScrapperConfigEnum::AMAZON_PRICE->value,
                    'value' => $amazonPrice,
                ],
                [
                    'name' => ConfigurationEnum::WEIGHT_UNIT->value,
                    'value' => $this->calcWeight($product),
                ],
            ],
            'custom_fields' => [
                [
                    'name' => ScrapperConfigEnum::AMAZON_ID->value,
                    'data' => $product['asin'],
                ],
                [
                    'name' => ScrapperConfigEnum::AMAZON_PRICE->value,
                    'data' => $amazonPrice,
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
        $categories = explode(' › ', $product['product_category']);
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

    public function calcWeight(array $product): float
    {
        $weight = null;

        if (isset($product['product_information']['product_dimensions'])) {
            $productDimensions = $product['product_information']['product_dimensions'];
            if (preg_match('/([\d.]+) x ([\d.]+) x ([\d.]+) inches; ([\d.]+) (Pounds|Ounces)/i', $productDimensions, $matches)) {
                $weight = (float) $matches[4];
                $unit = strtolower($matches[5]);

                $weight = $unit === 'ounces'
                    ? $weight * 28.3495
                    : $weight * 453.592;
            }
        }

        if (! $weight && isset($product['product_information']['item_weight'])) {
            $itemWeight = $product['product_information']['item_weight'];

            if (str_contains($itemWeight, 'ounces') || str_contains($itemWeight, 'Ounces')) {
                $weight = ((float) Str::before($itemWeight, 'ounces')) * 28.3495;
            } elseif (str_contains($itemWeight, 'pounds') || str_contains($itemWeight, 'Pounds')) {
                $weight = ((float) Str::before($itemWeight, 'pounds')) * 453.592;
            }
        }

        if (! $weight || $weight <= 0) {
            $weight = 453.592;
        }

        return (float) $weight;
    }

    public function calcDiscountPrice(array $product): array
    {
        $discount = 0;
        $amazonPrice = (float)$product['price'];
        $weight = $this->calcWeight($product) / 453.592;
        $deliveryCostMile = 2.50;
        $courierCost = $weight * 1.3;
        $gas = 1.02 * $weight;
        $dga = 0.15 * $weight;
        $airport = 0.07 * $weight;
        $insurance = 0;
        if ($amazonPrice > 100) {
            $insurance = 0.011 * (float)$amazonPrice;
        }
        $flete = $courierCost;
        $serviceFee = 1 * $weight;
        $otherFee = $gas + $dga + $airport + $insurance;
        $markUp = ((float)$amazonPrice * 1.15) - $amazonPrice;

        $payPerUser = $flete + $serviceFee + $otherFee + $markUp;
        $total = $amazonPrice + $payPerUser;

        $paymentFee = ($total * 0.029) + 0.3;
        $cpo = $deliveryCostMile + $courierCost + $gas + $dga + $airport + $insurance + $paymentFee;
        $gpo = $payPerUser - $cpo;
        $discountAmount = ($gpo * 0.75);
        $discount = $amazonPrice - $discountAmount;
        $discount = round($discount, 2);

        return ['total' => $total, 'discount' => $discount];
    }

    public function getDescription(array $product): string
    {
        return $product['full_description'] ?? $product['short_description'] ?? '';
    }
}
