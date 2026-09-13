<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Services;

use Illuminate\Support\Collection;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadVariantInterest;
use Kanvas\Inventory\Variants\Models\Variants;

final class LeadVariantInterestProjectionService
{
    public const array RELATIONS = [
        'variantInterests.variant.product',
        'variantInterests.variant.channels',
        'variantInterests.variant.variantAttributes.attribute',
    ];

    /**
     * @return array{items: list<array<string, mixed>>, search_text: string}
     */
    public function build(Lead $lead): array
    {
        $lead->loadMissing(self::RELATIONS);

        $items = $lead->variantInterests
            ->filter(fn (LeadVariantInterest $interest): bool => $this->isIndexable($lead, $interest))
            ->map(fn (LeadVariantInterest $interest): array => $this->item($interest))
            ->values();

        return [
            'items' => $items->all(),
            'search_text' => $this->searchText($items),
        ];
    }

    private function isIndexable(Lead $lead, LeadVariantInterest $interest): bool
    {
        $variant = $interest->variant;

        return ! $interest->is_deleted
            && $interest->is_active
            && (int) $interest->apps_id === (int) $lead->apps_id
            && (int) $interest->companies_id === (int) $lead->companies_id
            && $variant instanceof Variants
            && (int) $variant->apps_id === (int) $lead->apps_id
            && (int) $variant->companies_id === (int) $lead->companies_id
            && $variant->isPublished()
            && $variant->product !== null
            && ! $variant->product->is_deleted
            && (bool) $variant->product->is_published;
    }

    /** @return array<string, mixed> */
    private function item(LeadVariantInterest $interest): array
    {
        $variant = $interest->variant;

        return [
            'variant_id' => (int) $variant->getId(),
            'product_id' => (int) $variant->products_id,
            'name' => (string) $variant->name,
            'sku' => (string) $variant->sku,
            'product_name' => (string) $variant->product->name,
            'interest_type' => (string) $interest->interest_type,
            'current_price' => $variant->defaultChannelPrice(),
            'price_at_interest' => $interest->price_at_interest !== null
                ? (float) $interest->price_at_interest
                : null,
            'attributes' => $variant->loadedSearchableAttributes()->all(),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $items */
    private function searchText(Collection $items): string
    {
        return $items
            ->flatMap(fn (array $item): array => [
                $item['name'],
                $item['sku'],
                $item['product_name'],
                $item['interest_type'],
                ...collect($item['attributes'])
                    ->flatMap(fn (array $attribute): array => [$attribute['name'], $attribute['value']])
                    ->all(),
            ])
            ->filter(fn (mixed $value): bool => trim((string) $value) !== '')
            ->map(fn (mixed $value): string => trim((string) $value))
            ->unique()
            ->implode(' ');
    }
}
