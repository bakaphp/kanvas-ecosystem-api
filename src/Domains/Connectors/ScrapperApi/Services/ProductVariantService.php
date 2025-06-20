<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Services;

class ProductVariantService extends ProductService
{
    public function mapVariant(array $product): array
    {
        $variants = [];
        $variantsGroup = key_exists('customization_options', $product) ? $this->groupVariant($product['customization_options']) : [$product];
        foreach ($variantsGroup as $group) {
            $variant = $this->mapProduct($product);
            $variant['sku'] = $group['asin'];
            $variant['attributes'] = array_merge(
                $group['attributes'] ?? [],
                $product['attributes'] ?? []
            );
            $channel = [
                'price' => $variant['price'],
                'discounted_price' => $variant['discountPrice'],
                'is_published' => true,
                'warehouses_id' => $this->warehouse->getId(),
                'channels_id' => $this->channels->getId()
            ];
            $variant['channels'][] = $channel;

            if (isset($group['images'])) {
                $variant['files'] = $this->mapFilesystem(
                    product: [
                        'image' => $group['images'][0],
                        'images' => $group['images'],
                    ]
                );
            }
            $variant['name'] = key_exists('attributes', $group) ? $this->getName($group['attributes']) : $group['name'];
            $variants[] = $variant;
        }

        return $variants;
    }

    private function getAsinFromOption(array $customization): ?string
    {
        if (key_exists('asin', $customization)) {
            return $customization['asin'];
        } elseif (key_exists('url', $customization)) {
            if (preg_match('/(?:asin=|dp\/)([A-Z0-9]{10})/', $customization['url'], $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    private function groupVariant(array $customizationOptions): array
    {
        $variants = [];
        foreach ($customizationOptions as $key => $option) {
            $attribute = $key;
            foreach ($option as $value) {
                $asin = $this->getAsinFromOption($value);
                if (! $asin) {
                    continue;
                }
                if (key_exists($asin, $variants)) {
                    $variants[$asin]['attributes'][] = [
                        'name' => $attribute,
                        'value' => $value['value'] ?? '',
                    ];
                    if (isset($value['image']) && $value['image']) {
                        $variants[$asin]['images'][] = $value['image'];
                    }
                    continue;
                }
                $variants[$asin] = [
                    'asin' => $asin,
                    'attributes' => [
                        [
                            'name' => $attribute,
                            'value' => $value['value'] ?? '',
                        ],
                    ],
                ];
                if (isset($value['image']) && $value['image']) {
                    $variants[$asin]['images'][] = $value['image'];
                }
            }
        }

        $keys = count(array_keys($customizationOptions));
        $variants = array_filter($variants, function ($variant) use ($keys) {
            return count($variant['attributes']) == $keys;
        });

        return $variants;
    }

    public function getName(array $attributes): string
    {
        $name = '';
        foreach ($attributes as $attribute) {
            $name .= $attribute['name'] . ': ' . $attribute['value'] . ' ';
        }
        return trim($name);
    }
}
