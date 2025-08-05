<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapingDog\Services;

class ProductVariantService extends ProductService
{
    public function mapVariant(array $product): array
    {
        $variants = [];
        $variantsGroup = key_exists('customization_options', $product) ? $this->groupVariant($product['customization_options']) : [$product];

        foreach ($variantsGroup as $group) {
            $variant = $this->mapProduct($product);
            $variant['sku'] = $group['asin'];
            $variants['slug'] = $group['asin'];
            $variant['source_id'] = $group['asin'];

            // Merge attributes from variant and product
            $variant['attributes'] = array_merge(
                $group['attributes'] ?? [],
                $variant['attributes'] ?? []
            );

            // Setup channel information
            $channel = [
                'price' => $variant['price'],
                'discounted_price' => $variant['discountPrice'],
                'is_published' => true,
                'warehouses_id' => $this->warehouse->getId(),
                'channels_id' => $this->channels->getId(),
            ];
            $variant['channels'][] = $channel;

            $variant['name'] = $group['name'];

            $variants[] = $variant;
        }

        return $variants;
    }

    private function getAsinFromOption(array $customization): ?string
    {
        // Primero verificar si existe directamente el campo asin
        if (key_exists('asin', $customization)) {
            return $customization['asin'];
        }

        // Luego verificar en el campo url
        if (key_exists('url', $customization)) {
            if (preg_match('/(?:asin=|dp\/)([A-Z0-9]{10})/', $customization['url'], $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    private function groupVariant(array $customizationOptions): array
    {
        $variants = [];
        foreach ($customizationOptions as $optionType => $options) {
            if (! is_array($options)) {
                continue;
            }

            foreach ($options as $option) {
                $asin = $this->getAsinFromOption($option);
                if (! $asin) {
                    continue;
                }

                $variant = [
                    'asin' => $asin,
                    'name' => $option['value'] ?? '',
                ];

                if (isset($option['image']) && ! empty($option['image'])) {
                    $variant['images'] = [
                        $option['image'],
                    ];
                }
                if (isset($option['url'])) {
                    $variant['url'] = $option['url'];
                }

                if (isset($option['is_selected'])) {
                    $variant['is_selected'] = $option['is_selected'];
                }
                $variant['attributes'] = $this->getAttributes($asin, $customizationOptions);
                $variants[] = $variant;
            }
        }

        return $variants;
    }

    public function getAttributes(string $asin, array $customizations): array
    {
        $attributes = [];

        foreach ($customizations as $optionType => $options) {
            if (! is_array($options)) {
                continue;
            }

            foreach ($options as $option) {
                if (isset($option['asin']) && $option['asin'] === $asin) {
                    $attributes[] = [
                        'name' => $optionType,
                        'value' => $option['value'] ?? '',
                    ];
                }
            }
        }

        return $attributes;
    }

    public function getName(array $attributes): string
    {
        $nameParts = [];

        foreach ($attributes as $attribute) {
            if (isset($attribute['name']) && isset($attribute['value'])) {
                $nameParts[] = ucfirst($attribute['name']) . ': ' . $attribute['value'];
            }
        }

        return implode(' | ', $nameParts);
    }

    /**
     * Get all available variants for a product
     */
    public function getAvailableVariants(array $product): array
    {
        $availableVariants = [];

        if (! isset($product['customization_options'])) {
            return $availableVariants;
        }

        foreach ($product['customization_options'] as $optionType => $options) {
            if (! is_array($options)) {
                continue;
            }

            $availableVariants[$optionType] = [];

            foreach ($options as $option) {
                $availableVariants[$optionType][] = [
                    'value' => $option['value'] ?? '',
                    'asin' => $this->getAsinFromOption($option),
                    'is_selected' => $option['is_selected'] ?? false,
                    'url' => $option['url'] ?? null,
                ];
            }
        }

        return $availableVariants;
    }

    /**
     * Get the selected variant from customization options
     */
    public function getSelectedVariant(array $product): ?array
    {
        if (! isset($product['customization_options'])) {
            return null;
        }

        $selectedAttributes = [];

        foreach ($product['customization_options'] as $optionType => $options) {
            if (! is_array($options)) {
                continue;
            }

            foreach ($options as $option) {
                if (isset($option['is_selected']) && $option['is_selected'] === true) {
                    $selectedAttributes[] = [
                        'name' => $optionType,
                        'value' => $option['value'] ?? '',
                        'asin' => $this->getAsinFromOption($option),
                    ];

                    break;
                }
            }
        }

        if (empty($selectedAttributes)) {
            return null;
        }

        return [
            'asin' => $selectedAttributes[0]['asin'] ?? null,
            'attributes' => $selectedAttributes,
            'name' => $this->getName($selectedAttributes),
        ];
    }

    /**
     * Check if product has variants
     */
    public function hasVariants(array $product): bool
    {
        return isset($product['customization_options']) &&
               is_array($product['customization_options']) &&
               ! empty($product['customization_options']);
    }
}
