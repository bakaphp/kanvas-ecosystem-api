<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Services;

class ProductVariantService extends ProductService
{
    public function mapVariant(array $product): array
    {
        $variants = [];
        $variantsGroup = $this->groupVariant($product['customization_options']);
        foreach ($variantsGroup as $group) {
            $variant = $product;
            $variant['sku'] = $group['asin'];
            $variant['attributes'] = array_merge(
                $group['attributes'],
                $product['attributes'] ?? []
            );
            $variant['files'] = $this->mapFilesystem(
                product: [
                    'image' => $group['images'] ?? [$group['image']] ?? null,
                    'images' => [],
                ]
            );
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
                    'images' => isset($value['image']) && $value['image'] ? $value['image'] : null,
                ];
            }
        }

        $keys = count(array_keys($customizationOptions));
        $variants = array_filter($variants, function ($variant) use ($keys) {
            return count($variant['attributes']) == $keys;
        });

        return $variants;
    }
}
