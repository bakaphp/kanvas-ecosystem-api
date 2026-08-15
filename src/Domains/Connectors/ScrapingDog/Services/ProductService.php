<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapingDog\Services;

use Illuminate\Support\Str;
use Kanvas\Connectors\ScrapingDog\Enums\ConfigEnum as ScrapingDogConfigEnum;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Variants\Enums\ConfigurationEnum;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Users\Models\Users;

class ProductService
{
    public function __construct(
        protected Channels $channels,
        protected Warehouses $warehouse,
        protected Users $users,
    ) {
    }

    public function mapProduct(array $product): array
    {
        $weight = $this->calcWeight($product);
        $prices = $this->extractPrices($product);
        $amazonPrice = $prices['price'];
        $name = Str::limit($product['title'] ?? '', 255);
        $discountPrice = $prices['discountPrice'];
        $asin = $this->getProductAsin($product);
        $mappedProduct = [
            'name' => $name,
            'description' => $this->getDescription($product),
            'price' => $amazonPrice,
            'discountPrice' => $discountPrice,
            'slug' => Str::slug($asin),
            'sku' => $asin,
            'source' => 'amazon',
            'source_id' => $asin,
            'files' => $this->mapFilesystem($product),
            'quantity' => $this->channels->app->get(ScrapingDogConfigEnum::DEFAULT_QUANTITY->value) ?? 1,
            'isPublished' => true,
            'categories' => $this->mapCategories($product),
            'warehouses' => [
                [
                    'id' => $this->warehouse->id,
                    'price' => (float) $amazonPrice,
                    'warehouse' => $this->warehouse->name,
                    'quantity' => $this->channels->app->get(ScrapingDogConfigEnum::DEFAULT_QUANTITY->value) ?? 1,
                    'sku' => $asin,
                    'is_new' => true,
                    'channel' => $this->channels->name,
                ],
            ],
            'attributes' => [
                [
                    'name' => ScrapingDogConfigEnum::AMAZON_PRICE->value,
                    'value' => $amazonPrice,
                ],
                [
                    'name' => ConfigurationEnum::WEIGHT_UNIT->value,
                    'value' => $weight,
                ],
                [
                    'name' => ScrapingDogConfigEnum::SCRAPPER_BRAND->value,
                    'data' => $product['brand'] ?? '',
                ],
                [
                    'name' => ScrapingDogConfigEnum::SCRAPPER_RATING->value,
                    'data' => $product['average_rating'] ?? 0,
                ],
            ],
            'custom_fields' => [
                [
                    'name' => ScrapingDogConfigEnum::AMAZON_ID->value,
                    'data' => $asin,
                ],
                [
                    'name' => ScrapingDogConfigEnum::AMAZON_PRICE->value,
                    'data' => $amazonPrice,
                ],
                [
                    'name' => ConfigurationEnum::WEIGHT_UNIT->value,
                    'data' => $weight,
                ],
                [
                    'name' => ScrapingDogConfigEnum::SCRAPPER_BRAND->value,
                    'data' => $product['brand'] ?? 'Locompro',
                ],
                [
                    'name' => ScrapingDogConfigEnum::SCRAPPER_RATING->value,
                    'data' => $product['average_rating'] ?? 0,
                ],
                [
                    'name' => 'merchant_info',
                    'data' => $product['merchant_info'] ?? '',
                ],
                [
                    'name' => 'availability_status',
                    'data' => $product['availability_status'] ?? '',
                ],
                [
                    'name' => 'shipping_info',
                    'data' => $product['shipping_info'] ?? '',
                ],
                [
                    'name' => 'total_reviews',
                    'data' => $product['total_reviews'] ?? '',
                ],
                [
                    'name' => 'product_information',
                    'data' => $product['product_information'] ?? [],
                ],
                [
                    'name' => 'feature_bullets',
                    'data' => $product['feature_bullets'] ?? [],
                ]
            ],
        ];

        return $mappedProduct;
    }

    public function mapCategories(array $product): array
    {
        if (! isset($product['product_category'])) {
            return [];
        }

        $categories = explode('›', $product['product_category']);
        $mappedCategories = [];

        foreach ($categories as $category) {
            $category = trim($category);
            if (empty($category)) {
                continue;
            }
            $mappedCategories[] = [
                'name' => $category,
                'slug' => Str::slug($category),
                'code' => Str::slug($category),
                'position' => 0,
            ];

            break; // @todo: work with subcategories in the future
        }

        return $mappedCategories;
    }

    protected function mapFilesystem(array $product): array
    {
        $files = [];

        // Imagen principal
        if (isset($product['main_image']) && ! empty($product['main_image'])) {
            $files[] = [
                'url' => $product['main_image'],
                'name' => 'main_image',
            ];
        }

        // Imágenes específicas del ASIN
        if (isset($product['images_of_specified_asin']) && is_array($product['images_of_specified_asin'])) {
            foreach ($product['images_of_specified_asin'] as $image) {
                if (! empty($image)) {
                    $files[] = [
                        'url' => $image,
                        'name' => 'asin_' . basename($image),
                    ];
                }
            }
        } elseif (isset($product['images']) && is_array($product['images'])) {
            foreach ($product['images'] as $image) {
                if (! empty($image)) {
                    $files[] = [
                        'url' => $image,
                        'name' => basename($image),
                    ];
                }
            }
        }

        return $files;
    }

    public function calcWeight(array $product): float
    {
        $weight = null;

        if (! empty($product['product_information']['Item Weight'])) {
            $weight = $this->parseWeightString($product['product_information']['Item Weight']);
        }

        if ((! $weight || $weight <= 0) && ! empty($product['product_information']['Product Dimensions'])) {
            $weight = $this->parseWeightString($product['product_information']['Product Dimensions']);
        }

        if ((! $weight || $weight <= 0) && ! empty($product['feature_bullets']) && is_array($product['feature_bullets'])) {
            foreach ($product['feature_bullets'] as $bullet) {
                $weight = $this->parseWeightString($bullet);
                if ($weight > 0) {
                    break;
                }
            }
        }
        if (! $weight || $weight <= 0) {
            $weight = 453.592;
        }

        return (float) $weight;
    }

    private function parseWeightString(string $text): float
    {
        if (preg_match('/([\d.]+)\s*(pounds|ounces|lbs|oz|kg|g)\b/i', $text, $matches)) {
            $value = (float) $matches[1];
            $unit = strtolower($matches[2]);

            return match ($unit) {
                'pounds', 'lbs' => $value * 453.592,
                'ounces', 'oz' => $value * 28.3495,
                'kg' => $value * 1000,
                'g' => $value,
                default => 0,
            };
        }

        return 0;
    }

    public function calcDiscountPrice(array $product): array
    {
        $discount = 0;
        $amazonPrice = $this->extractPrices($product)['discountPrice'];
        $weight = $this->calcWeight($product) / 453.592;
        $deliveryCostMile = 2.50;
        $courierCost = $weight * 1.3;
        $gas = 1.02 * $weight;
        $dga = 0.15 * $weight;
        $airport = 0.07 * $weight;
        $insurance = 0;

        if ($amazonPrice > 100) {
            $insurance = 0.011 * $amazonPrice;
        }

        $flete = $courierCost;
        $serviceFee = 1 * $weight;
        $otherFee = $gas + $dga + $airport + $insurance;
        $markUp = ($amazonPrice * 1.15) - $amazonPrice;

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
        if (! empty($product['description'])) {
            return $product['description'];
        }
        if (isset($product['feature_bullets']) && is_array($product['feature_bullets'])) {
            return implode("\n", $product['feature_bullets']);
        }

        if (isset($product['author']['description']) && ! empty($product['author']['description'])) {
            return trim($product['author']['description']);
        }

        return '';
    }

    /**
     * @return array{price: float, discountPrice: float}
     */
    protected function extractPrices(array $product): array
    {
        $currentPrice = $this->extractCurrentPrice($product);
        $listPrice = $this->extractListPrice($product);

        if ($listPrice <= 0) {
            $listPrice = $this->extractOriginalPriceFromSavings($product);
        }

        $regularPrice = $listPrice > 0 ? $listPrice : $currentPrice;
        $discountPrice = $currentPrice > 0 ? $currentPrice : $regularPrice;

        return [
            'price' => $regularPrice,
            'discountPrice' => $discountPrice,
        ];
    }

    protected function extractCurrentPrice(array $product): float
    {
        preg_match('/\$?([\d,]+\.?\d*)/', (string) ($product['price'] ?? ''), $matches);

        return isset($matches[1])
            ? (float) str_replace(',', '', $matches[1])
            : 0.0;
    }

    protected function extractOriginalPriceFromSavings(array $product): float
    {
        $price = (string) ($product['price'] ?? '');
        if (! preg_match('/\$?([\d,]+\.?\d*)\s+with\s+(\d+)\s+percent\s+savings/i', $price, $matches)) {
            return 0.0;
        }

        $discountedPrice = (float) str_replace(',', '', $matches[1]);
        $discountPercent = (int) $matches[2];

        if ($discountPercent <= 0 || $discountPercent >= 100) {
            return 0.0;
        }

        return round(($discountedPrice * 100) / (100 - $discountPercent), 2);
    }

    protected function extractListPrice(array $product): float
    {
        $price = str_replace(['$', ','], '', $product['list_price'] ?? '');

        return (float) $price;
    }

    protected function getProductAsin(array $product): string
    {
        if (isset($product['parent_asin']) && ! empty($product['parent_asin'])) {
            return $product['parent_asin'];
        }

        if (isset($product['customization_options']['size'])) {
            foreach ($product['customization_options']['size'] as $option) {
                if (isset($option['is_selected']) && $option['is_selected'] === true) {
                    return $option['asin'];
                }
            }
        }

        if (isset($product['customization_options']['style'])) {
            foreach ($product['customization_options']['style'] as $option) {
                if (isset($option['is_selected']) && $option['is_selected'] === true) {
                    return $option['asin'];
                }
            }
        }

        return 'UNKNOWN_' . Str::slug($product['title'] ?? 'product');
    }
}
