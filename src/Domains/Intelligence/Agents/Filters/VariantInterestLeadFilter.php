<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\LeadVariantInterestProjectionService;
use Kanvas\Intelligence\Agents\Services\VariantInterestSearchService;

class VariantInterestLeadFilter
{
    public function __construct(
        private readonly VariantInterestSearchService $search = new VariantInterestSearchService(),
        private readonly LeadVariantInterestProjectionService $projection = new LeadVariantInterestProjectionService(),
    ) {
    }

    /** @return array{active: bool, variants: list<array<string, mixed>>, criteria: array<string, mixed>, minimum_price: float|null, maximum_price: float|null} */
    public function apply(
        Builder $query,
        Apps $app,
        Companies $company,
        array $filters
    ): array {
        $attributes = $filters['variant_attributes'] ?? [];
        $attributes = is_array($attributes) ? $attributes : [];
        $variantQuery = trim((string) ($filters['variant_query'] ?? ''));
        $minimumPrice = $filters['minimum_variant_price'] ?? null;
        $maximumPrice = $filters['maximum_variant_price'] ?? null;
        $active = $variantQuery !== '' || $attributes !== [] || $minimumPrice !== null || $maximumPrice !== null;
        $variants = [];

        if ($active) {
            $variants = $this->search->resolve(
                $app,
                $company,
                $variantQuery,
                $attributes
            );
            $variantIds = array_map(static fn (array $variant): int => (int) $variant['id'], $variants);
            $query->whereHas('variantInterests', fn ($interest) => $interest
                ->where('is_active', 1)
                ->where('is_deleted', 0)
                ->whereIn('variants_id', $variantIds === [] ? [-1] : $variantIds)
                ->when($minimumPrice !== null, fn ($price) => $price->where('price_at_interest', '>=', $minimumPrice))
                ->when($maximumPrice !== null, fn ($price) => $price->where('price_at_interest', '<=', $maximumPrice)));
        }

        return [
            'active' => $active,
            'variants' => $variants,
            'criteria' => $active ? [
                'query' => $variantQuery,
                'attributes' => $attributes,
                'minimum_price' => $minimumPrice,
                'maximum_price' => $maximumPrice,
            ] : [],
            'minimum_price' => $minimumPrice,
            'maximum_price' => $maximumPrice,
        ];
    }

    /** @param array<string, mixed> $context */
    public function attachMatches(Collection $leads, array $rows, array $context): array
    {
        if (! $context['active']) {
            return $rows;
        }

        $variantIds = array_fill_keys(
            array_map(static fn (array $variant): int => (int) $variant['id'], $context['variants']),
            true,
        );
        $byLead = $leads->mapWithKeys(fn (Lead $lead): array => [
            $lead->getId() => array_values(array_filter(
                $this->projection->build($lead)['items'],
                fn (array $interest): bool => isset($variantIds[(int) $interest['variant_id']])
                    && $this->matchesPrice($interest['price_at_interest'], $context),
            )),
        ])->all();

        return array_map(
            static fn (array $row): array => [
                ...$row,
                'matched_variant_interests' => $byLead[(int) $row['lead_id']] ?? [],
            ],
            $rows,
        );
    }

    /** @param array<string, mixed> $context */
    private function matchesPrice(?float $price, array $context): bool
    {
        if ($price === null) {
            return $context['minimum_price'] === null && $context['maximum_price'] === null;
        }

        return ($context['minimum_price'] === null || $price >= $context['minimum_price'])
            && ($context['maximum_price'] === null || $price <= $context['maximum_price']);
    }
}
