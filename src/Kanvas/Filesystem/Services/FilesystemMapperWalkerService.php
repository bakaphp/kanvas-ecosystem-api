<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Services;

use Baka\Validations\Date;
use Illuminate\Support\Str;
use Kanvas\Social\Tags\Models\Tag;

/**
 * Interprets a `FilesystemMapper.mapping` template against one row of raw source data, whatever
 * produced it — a CSV row, a connector's API response, a webhook payload.
 */
class FilesystemMapperWalkerService
{
    /**
     * Mapper keys that live on a source row but mean something different once the row becomes a
     * variant — `variant_name` is the variant's `name`, and `variant_tags` its `tags` (product-level
     * tags come from `product_tags`).
     */
    private const array VARIANT_KEY_ALIASES = [
        'variant_name' => 'name',
        'variant_tags' => 'tags',
    ];

    public function walk(array $template, array $data): array
    {
        $result = [];

        foreach ($template as $key => $value) {
            $targetKey = self::VARIANT_KEY_ALIASES[$key] ?? $key;

            if ($key === 'attributes' && is_array($value)) {
                $result[$targetKey] = $this->mapAttributes($value, $data);

                continue;
            }

            if (is_array($value)) {
                $result[$targetKey] = $this->walk($value, $data);

                continue;
            }

            $result[$targetKey] = match (true) {
                is_string($value) && str_starts_with($value, '_') => substr($value, 1),
                is_string($value) && str_starts_with($value, 'date_') => Date::createFromFormat($data[substr($value, 5)] ?? ''),
                is_string($value) => $data[$value] ?? null,
                default => $value,
            };

            if ($targetKey === 'categories' && is_string($result[$targetKey]) && $result[$targetKey] !== '') {
                $result[$targetKey] = $this->mapCategories($result[$targetKey]);
            } elseif ($targetKey === 'tags' || $targetKey === 'product_tags') {
                $result[$targetKey] = Tag::normalizeNames($result[$targetKey]);
            } elseif ($targetKey === 'files' && is_string($result[$targetKey]) && $result[$targetKey] !== '') {
                $result[$targetKey] = Date::explodeFileStringBasedOnDelimiter($result[$targetKey]);
            } elseif (is_string($result[$targetKey]) && Date::isValidDate($result[$targetKey])) {
                $result[$targetKey] = Date::createFromFormat($result[$targetKey]);
            }
        }

        return $result;
    }

    private function mapAttributes(array $attributeTemplate, array $data): array
    {
        $mappedAttributes = $this->walk($attributeTemplate, $data);
        $result = [];

        foreach ($mappedAttributes as $attributeData) {
            if (! is_array($attributeData)) {
                continue;
            }

            $fromProduct = $attributeData['fromProduct'] ?? false;

            foreach ($attributeData as $key => $value) {
                if ($key !== 'fromProduct') {
                    $result[] = [
                        'fromProduct' => $fromProduct,
                        'name' => $key,
                        'value' => $value,
                    ];
                }
            }
        }

        return $result;
    }

    private function mapCategories(string $categoriesString): array
    {
        $categories = array_map('trim', explode(',', $categoriesString));

        return array_map(function ($categoryName) {
            return [
                'name' => $categoryName,
                'slug' => Str::slug($categoryName),
            ];
        }, array_filter($categories));
    }
}
