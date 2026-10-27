<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\DataTransferObject;

use Baka\Support\Number;
use Baka\Support\Str;
use Kanvas\Inventory\Recommendations\Discovery\NumericFilter;
use Kanvas\Inventory\Recommendations\Enums\AudienceEnum;
use Kanvas\Inventory\Recommendations\Services\IntentLexiconService;
use Spatie\LaravelData\Data;

/**
 * The constraints a shopper is never wrong about, and which an embedding cannot
 * enforce: a budget, and who the gift is for. Occasion and style stay with the
 * embedding, where being approximately right is good enough.
 */
class ProductIntent extends Data
{
    /**
     * A number followed by one of these is a measurement, never a budget:
     * "under 100k miles" is a mileage limit and "mas de 30 años" an age.
     */
    private const string NON_PRICE_UNITS = 'miles?|mi|millas?|kms?|kilometers?|kilometros?|anos?|years?|yrs?';

    private const string CURRENCY_WORDS = 'dollars?|dolares?|usd|pesos?';

    /**
     * @param list<string> $preferences descriptive words that may boost but must never be required
     * @param list<string> $alternativeQueries extra phrasings that keep the identity terms
     * @param list<NumericFilter> $numericFilters
     */
    public function __construct(
        public readonly string $sentence,
        public readonly ?float $minPrice = null,
        public readonly ?float $maxPrice = null,
        public readonly bool $inStockOnly = false,
        public readonly ?AudienceEnum $audience = null,
        public readonly ?string $query = null,
        public readonly array $preferences = [],
        public readonly array $alternativeQueries = [],
        public readonly ?string $category = null,
        public readonly array $numericFilters = [],
    ) {
    }

    /**
     * The one place explicit, already-validated caller input (the agent's tool
     * call, a storefront's filter UI) is laid over what was parsed from the
     * sentence. An explicit value wins; an omitted one keeps the parsed value.
     *
     * @param list<string> $preferences
     * @param list<string> $alternativeQueries
     * @param list<NumericFilter> $numericFilters
     */
    public function withOverrides(
        ?string $query = null,
        array $preferences = [],
        array $alternativeQueries = [],
        ?float $minPrice = null,
        ?float $maxPrice = null,
        ?AudienceEnum $audience = null,
        ?string $category = null,
        array $numericFilters = [],
    ): self {
        $hasExplicitPrice = $minPrice !== null || $maxPrice !== null;

        return new self(
            sentence: $this->sentence,
            minPrice: $hasExplicitPrice ? $minPrice : $this->minPrice,
            maxPrice: $hasExplicitPrice ? $maxPrice : $this->maxPrice,
            inStockOnly: $this->inStockOnly,
            audience: $audience ?? $this->audience,
            query: Str::trimToNull($query) ?? $this->query,
            preferences: $preferences !== [] ? $preferences : $this->preferences,
            alternativeQueries: $alternativeQueries !== [] ? $alternativeQueries : $this->alternativeQueries,
            category: $category ?? $this->category,
            numericFilters: $numericFilters !== [] ? $numericFilters : $this->numericFilters,
        );
    }

    /**
     * The whole intent as plain data, for the impression log. Plain arrays travel
     * through a queued job safely; this Spatie Data object would not.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'query' => $this->query,
            'preferences' => $this->preferences,
            'alternative_queries' => $this->alternativeQueries,
            'min_price' => $this->minPrice,
            'max_price' => $this->maxPrice,
            'in_stock_only' => $this->inStockOnly,
            'audience' => $this->audience?->value,
            'category' => $this->category,
            'numeric_filters' => array_map(
                static fn (NumericFilter $filter): array => [
                    'field' => $filter->field,
                    'min' => $filter->min,
                    'max' => $filter->max,
                ],
                $this->numericFilters,
            ),
        ];
    }

    /**
     * Constraints the shopper is never wrong about. When any is present only the
     * variants that satisfy all of them together may be shown.
     */
    public function hasHardConstraints(): bool
    {
        return $this->hasPriceConstraint() || $this->numericFilters !== [] || $this->category !== null;
    }

    public static function fromSentence(
        string $sentence,
        IntentLexiconService $lexicon,
        bool $inStockOnly = false,
    ): self {
        $normalized = IntentLexiconService::normalize($sentence);

        [$minPrice, $maxPrice] = self::matchPriceRange($normalized);

        if ($minPrice === null && $maxPrice === null) {
            [$minPrice, $maxPrice] = self::matchDirectedPrice($normalized, $lexicon);
        }

        if ($minPrice === null && $maxPrice === null) {
            $maxPrice = self::matchBareCurrencyAmount($normalized);
        }

        if ($minPrice === null && $maxPrice === null) {
            [$minPrice, $maxPrice] = self::matchVaguePriceBand($normalized, $lexicon);
        }

        return new self(
            sentence: $sentence,
            minPrice: $minPrice,
            maxPrice: $maxPrice,
            inStockOnly: $inStockOnly,
            audience: $lexicon->matchAudience($normalized),
        );
    }

    public function hasPriceConstraint(): bool
    {
        return $this->minPrice !== null || $this->maxPrice !== null;
    }

    /**
     * "between 30-40k", "entre $30 y $40", "de 30 a 40 mil". A bare "2018 and 2020"
     * is model years, not money, so a range needs a money signal: a `$`, a
     * thousands suffix, or a currency word after it. A suffix on the second
     * number only ("30-40k") applies to both.
     *
     * @return array{0: float|null, 1: float|null} [min, max]
     */
    private static function matchPriceRange(string $normalized): array
    {
        $pattern = '/(?:\b(?:between|entre)\s+)?' . self::amountPattern('a')
            . '\s*(?:-|to|and|y|a)\s*' . self::amountPattern('b') . '/u';

        if (preg_match_all($pattern, $normalized, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
            return [null, null];
        }

        foreach ($all as $match) {
            $end = $match[0][1] + strlen($match[0][0]);
            $hasSymbol = self::group($match, 'dollar_a') !== null || self::group($match, 'dollar_b') !== null;
            $suffixA = self::group($match, 'suffix_a');
            $suffixB = self::group($match, 'suffix_b');

            if (self::followedBy($normalized, $end, self::NON_PRICE_UNITS)) {
                continue;
            }

            $hasMoneySignal = $hasSymbol
                || $suffixA !== null
                || $suffixB !== null
                || self::followedBy($normalized, $end, self::CURRENCY_WORDS);

            $low = Number::toFloat((string) self::group($match, 'num_a'));
            $high = Number::toFloat((string) self::group($match, 'num_b'));

            if (! $hasMoneySignal || $low === null || $high === null) {
                continue;
            }

            $low = Number::applyMultiplier($low, $suffixA ?? $suffixB);
            $high = Number::applyMultiplier($high, $suffixB);

            if ($low <= $high) {
                return [$low, $high];
            }
        }

        return [null, null];
    }

    /**
     * Every directive in the sentence is read, not just the first: "under 100k
     * miles … no more than $20 thousand" must skip the mileage and keep the
     * budget, and "more than 10k and less than 20k" sets both ends.
     *
     * @return array{0: float|null, 1: float|null} [min, max]
     */
    private static function matchDirectedPrice(string $normalized, IntentLexiconService $lexicon): array
    {
        $directives = $lexicon->priceDirectives();

        if ($directives === []) {
            return [null, null];
        }

        $alternation = implode('|', array_map('preg_quote', array_keys($directives)));
        $pattern = '/\b(?<directive>' . $alternation . ')\s*' . self::amountPattern('a') . '/u';

        if (preg_match_all($pattern, $normalized, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
            return [null, null];
        }

        $minPrice = null;
        $maxPrice = null;

        foreach ($all as $match) {
            $amount = self::amountFrom($normalized, $match);

            if ($amount === null) {
                continue;
            }

            if ($directives[(string) self::group($match, 'directive')] === IntentLexiconService::MIN_PRICE) {
                $minPrice ??= $amount;
            } else {
                $maxPrice ??= $amount;
            }
        }

        return [$minPrice, $maxPrice];
    }

    /**
     * Currency symbol required: "una mujer de 32 años" would otherwise cap the budget at $32.
     */
    private static function matchBareCurrencyAmount(string $normalized): ?float
    {
        $pattern = '/' . self::amountPattern('a') . '/u';

        if (preg_match_all($pattern, $normalized, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
            return null;
        }

        foreach ($all as $match) {
            if (self::group($match, 'dollar_a') === null) {
                continue;
            }

            $amount = self::amountFrom($normalized, $match);

            if ($amount !== null) {
                return $amount;
            }
        }

        return null;
    }

    private static function amountPattern(string $name): string
    {
        return '(?<dollar_' . $name . '>\$\s*)?(?<num_' . $name . '>\d[\d.,]*)\s*'
            . '(?<suffix_' . $name . '>' . Number::multiplierPattern() . ')?(?![\p{L}\d])';
    }

    /**
     * @param array<int|string, array{0: string, 1: int}> $match
     */
    private static function amountFrom(string $normalized, array $match): ?float
    {
        $end = $match[0][1] + strlen($match[0][0]);

        if (self::followedBy($normalized, $end, self::NON_PRICE_UNITS)) {
            return null;
        }

        $amount = Number::toFloat((string) self::group($match, 'num_a'));

        return $amount === null
            ? null
            : Number::applyMultiplier($amount, self::group($match, 'suffix_a'));
    }

    private static function followedBy(string $normalized, int $offset, string $alternation): bool
    {
        return preg_match('/\G\s*(?:' . $alternation . ')\b/u', $normalized, $unused, 0, $offset) === 1;
    }

    /**
     * An unmatched optional group comes back as ['', -1] from PREG_OFFSET_CAPTURE.
     *
     * @param array<int|string, array{0: string, 1: int}> $match
     */
    private static function group(array $match, string $name): ?string
    {
        $value = $match[$name][0] ?? '';

        return $value === '' || ($match[$name][1] ?? -1) === -1 ? null : $value;
    }

    /**
     * @return array{0: float|null, 1: float|null} [min, max]
     */
    private static function matchVaguePriceBand(string $normalized, IntentLexiconService $lexicon): array
    {
        if ($lexicon->matches($normalized, IntentLexiconService::PREMIUM)) {
            return [$lexicon->premiumMinPrice(), null];
        }

        if ($lexicon->matches($normalized, IntentLexiconService::CHEAP)) {
            return [null, $lexicon->cheapMaxPrice()];
        }

        return [null, null];
    }
}
