<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Services;

use Baka\Validations\Date;
use Illuminate\Support\Str;

/**
 * Interprets a `FilesystemMapper.mapping` template against one row of raw source data —
 * originally lived inside `ImportProductFromFilesystemAction`, extracted so any entity (not just
 * Products) can turn a mapper + raw record into Kanvas-shaped data, regardless of where the raw
 * record came from (a CSV row, a connector's API response, ...).
 */
class FilesystemMapperWalker
{
    public function walk(array $template, array $data): array
    {
        $result = [];

        foreach ($template as $key => $value) {
            $targetKey = ($key === 'variant_name') ? 'name' : $key;

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
