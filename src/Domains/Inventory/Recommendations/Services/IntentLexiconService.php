<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Services;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Str;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum;

/**
 * Shipped config is English; a tenant's own language is MERGED over it, never
 * swapped — one storefront receives both "menos de $50" and "under 50".
 */
class IntentLexiconService
{
    public const string MAX_PRICE = 'max_price';
    public const string MIN_PRICE = 'min_price';
    public const string PREMIUM = 'premium';
    public const string CHEAP = 'cheap';

    /** @var array<string, list<string>> */
    private array $cache = [];

    public function __construct(
        private readonly AppInterface $app,
    ) {
    }

    /**
     * Users type "maximo" as often as "máximo". Not Str::slug() — it hyphenates,
     * which destroys the multi-word phrases the lexicon matches on.
     */
    public static function normalize(string $value): string
    {
        return Str::lower(Str::ascii(trim($value)));
    }

    /**
     * @return list<string> normalized, deduped, longest phrase first
     */
    public function termsFor(string $bucket): array
    {
        if (isset($this->cache[$bucket])) {
            return $this->cache[$bucket];
        }

        $terms = array_values(array_unique(array_filter(array_map(
            static fn (mixed $term): string => is_string($term) ? self::normalize($term) : '',
            [...$this->shippedTerms($bucket), ...$this->tenantTerms($bucket)],
        ))));

        $this->sortLongestFirst($terms);

        return $this->cache[$bucket] = $terms;
    }

    /**
     * Both buckets in one globally-ordered map, longest first: "no mas de" (a max)
     * contains "mas de" (a min), and PCRE takes the first matching alternative —
     * shorter-first would parse "no mas de 50" as a floor and invert the filter.
     *
     * @return array<string, string> phrase => bucket
     */
    public function priceDirectives(): array
    {
        $tagged = [];

        foreach ([self::MAX_PRICE, self::MIN_PRICE] as $bucket) {
            foreach ($this->termsFor($bucket) as $term) {
                $tagged[$term] ??= $bucket;
            }
        }

        $terms = array_keys($tagged);
        $this->sortLongestFirst($terms);

        $ordered = [];
        foreach ($terms as $term) {
            $ordered[$term] = $tagged[$term];
        }

        return $ordered;
    }

    /**
     * @param string $normalized a sentence already put through normalize()
     */
    public function matches(string $normalized, string $bucket): bool
    {
        $pattern = $this->patternFor($bucket);

        return $pattern !== null && preg_match('/\b' . $pattern . '\b/u', $normalized) === 1;
    }

    public function patternFor(string $bucket): ?string
    {
        $terms = $this->termsFor($bucket);

        return $terms === []
            ? null
            : '(?:' . implode('|', array_map('preg_quote', $terms)) . ')';
    }

    public function premiumMinPrice(): float
    {
        return $this->tenantFloat(ConfigurationEnum::PREMIUM_MIN_PRICE)
            ?? (float) config('inventory-discovery.premium_min_price', 100.0);
    }

    public function cheapMaxPrice(): float
    {
        return $this->tenantFloat(ConfigurationEnum::CHEAP_MAX_PRICE)
            ?? (float) config('inventory-discovery.cheap_max_price', 50.0);
    }

    /**
     * @param list<string> $terms
     */
    private function sortLongestFirst(array &$terms): void
    {
        usort($terms, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a) ?: strcmp($a, $b));
    }

    /**
     * @return list<mixed>
     */
    private function shippedTerms(string $bucket): array
    {
        $terms = config('inventory-discovery.intent_lexicon.' . $bucket, []);

        return is_array($terms) ? array_values($terms) : [];
    }

    /**
     * @return list<mixed>
     */
    private function tenantTerms(string $bucket): array
    {
        $lexicon = ConfigurationEnum::INTENT_LEXICON->listFrom($this->app);

        return isset($lexicon[$bucket]) && is_array($lexicon[$bucket])
            ? array_values($lexicon[$bucket])
            : [];
    }

    private function tenantFloat(ConfigurationEnum $key): ?float
    {
        $value = $this->app->get($key->value);

        return is_numeric($value) ? (float) $value : null;
    }
}
