<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ProductEnrichment\DataTransferObject;

use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * Per-app enrichment vocabulary + price tiers. Resolved from the enrichment
 * Agent's `config.enrichment` (UI-editable / auto-discovered) with an in-code
 * gift-store default — same default→override pattern as the agent instructions.
 */
final class EnrichmentConfig
{
    /**
     * @var array<string, list<string>>
     */
    private const array DEFAULT_FACETS = [
        'audience' => ['male', 'female', 'unisex', 'kids', 'baby', 'teen', 'senior'],
        'occasion' => ['birthday', 'anniversary', 'wedding', 'christmas', 'valentines', 'graduation', 'housewarming', 'general'],
        'interests' => ['coffee', 'cooking', 'fashion', 'jewelry', 'fitness', 'reading', 'tech', 'beauty', 'home', 'travel'],
        'tags' => ['elegant', 'romantic', 'practical', 'sentimental', 'fun', 'sporty', 'luxury', 'eco', 'personalizable'],
    ];

    /**
     * @var array<string, array{0: float, 1: float|null}>
     */
    private const array DEFAULT_PRICE_TIERS = [
        'budget' => [0, 500],
        'mid' => [500, 1500],
        'premium' => [1500, 3500],
        'luxury' => [3500, null],
    ];

    /**
     * @param array<string, list<string>> $facets       audience/occasion/interests/tags → allowed values
     * @param array<string, array{0: float, 1: float|null}> $priceTiers  tier → [min, maxExclusive|null]
     */
    public function __construct(
        public readonly array $facets,
        public readonly array $priceTiers,
    ) {
    }

    public static function forAgent(?Agent $agent): self
    {
        $config = $agent?->config['enrichment'] ?? [];

        return new self(
            facets: $config['facets'] ?? self::DEFAULT_FACETS,
            priceTiers: $config['price_tiers'] ?? self::DEFAULT_PRICE_TIERS,
        );
    }

    /**
     * Keep only values that exist in this app's controlled vocabulary — the
     * guardrail that stops the LLM from polluting facets with invented tags.
     *
     * @param array<array-key, mixed> $values
     *
     * @return list<string>
     */
    public function clean(string $facet, array $values): array
    {
        $allowed = $this->facets[$facet] ?? [];

        return array_values(array_filter(
            array_map(static fn ($v): string => (string) $v, $values),
            static fn (string $v): bool => in_array($v, $allowed, true),
        ));
    }

    public function priceTier(?float $price): ?string
    {
        if ($price === null) {
            return null;
        }

        foreach ($this->priceTiers as $tier => [$min, $max]) {
            if ($price >= $min && ($max === null || $price < $max)) {
                return $tier;
            }
        }

        return null;
    }
}
