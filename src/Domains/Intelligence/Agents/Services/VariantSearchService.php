<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsAttributes;

class VariantSearchService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(Apps $app, Companies $company, string $keyword, int $limit = 20): array
    {
        $variants = Variants::search($keyword)
            ->where('apps_id', $app->getId())
            ->where('company.id', $company->getId())
            ->take($limit)
            ->get();

        $variants->load([
            'product',
            'channels',
            'variantAttributes.attribute',
        ]);

        return $variants->map(fn (Variants $variant) => [
            'id' => $variant->getId(),
            'name' => $variant->name,
            'sku' => $variant->sku,
            'product' => $variant->product?->name,
            'is_published' => (bool) $variant->is_published,
            'stock' => $variant->getTotalQuantity(),
            'price' => $variant->channels
                ->first(fn ($channel): bool => (bool) $channel->is_default && (bool) $channel->pivot?->is_published)
                ?->pivot?->price,
            'attributes' => $variant->variantAttributes
                ->filter(fn (VariantsAttributes $value): bool => ! $value->is_deleted && (bool) $value->attribute?->is_searchable)
                ->mapWithKeys(fn (VariantsAttributes $value): array => [
                    (string) $value->attribute?->name => $this->stringValue($value->value),
                ])
                ->filter(fn (string $value, string $name): bool => $name !== '' && $value !== '')
                ->all(),
        ])->toArray();
    }

    private function stringValue(mixed $value): string
    {
        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return is_array($value)
            ? collect($value)->flatten()->filter(fn (mixed $item): bool => is_scalar($item))->implode(', ')
            : '';
    }
}
