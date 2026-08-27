<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;

class VariantInterestSearchService
{
    public function __construct(
        private readonly VariantSearchService $variantSearch = new VariantSearchService(),
    ) {
    }

    /**
     * @param list<string> $attributeFilters
     * @return list<array<string, mixed>>
     */
    public function resolve(
        Apps $app,
        Companies $company,
        string $query,
        array $attributeFilters = [],
        int $limit = 1000,
    ): array {
        $filters = $this->parseAttributeFilters($attributeFilters);

        return collect($this->variantSearch->search($app, $company, $query === '' ? '*' : $query, $limit))
            ->filter(fn (array $variant): bool => $this->matchesAttributes($variant, $filters))
            ->values()
            ->all();
    }

    /** @param list<string> $filters */
    private function parseAttributeFilters(array $filters): array
    {
        return collect($filters)
            ->mapWithKeys(function (string $filter): array {
                [$name, $value] = array_pad(explode(':', $filter, 2), 2, '');

                return [mb_strtolower(trim($name)) => mb_strtolower(trim($value))];
            })
            ->filter(fn (string $value, string $name): bool => $name !== '' && $value !== '')
            ->all();
    }

    /** @param array<string, string> $filters */
    private function matchesAttributes(array $variant, array $filters): bool
    {
        if ($filters === []) {
            return true;
        }

        $attributes = collect($variant['attributes'] ?? [])
            ->mapWithKeys(fn (mixed $value, mixed $name): array => [
                mb_strtolower(trim((string) $name)) => mb_strtolower(trim((string) $value)),
            ]);

        foreach ($filters as $name => $value) {
            if (! str_contains((string) $attributes->get($name, ''), $value)) {
                return false;
            }
        }

        return true;
    }
}
