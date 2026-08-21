<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\DataTransferObject;

use Kanvas\Inventory\Recommendations\Services\IntentLexiconService;
use Spatie\LaravelData\Data;

/**
 * The hard constraints pulled out of a free-form query.
 *
 * Deliberately shallow: a price ceiling has to become a `filter_by` because no
 * similarity score will reliably keep a $900 watch out of a "under $50" result.
 * Everything else the sentence carries — recipient, occasion, style, tone — is
 * left to the embedding, which is multilingual and needs no rules here.
 */
class ProductIntent extends Data
{
    public function __construct(
        public readonly string $sentence,
        public readonly ?float $minPrice = null,
        public readonly ?float $maxPrice = null,
        public readonly bool $inStockOnly = false,
    ) {
    }

    public static function fromSentence(
        string $sentence,
        IntentLexiconService $lexicon,
        bool $inStockOnly = false,
    ): self {
        $normalized = IntentLexiconService::normalize($sentence);

        [$minPrice, $maxPrice] = self::matchDirectedPrice($normalized, $lexicon);

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
        );
    }

    public function hasPriceConstraint(): bool
    {
        return $this->minPrice !== null || $this->maxPrice !== null;
    }

    /**
     * @return array{0: float|null, 1: float|null} [min, max]
     */
    private static function matchDirectedPrice(string $normalized, IntentLexiconService $lexicon): array
    {
        $directives = $lexicon->priceDirectives();

        if ($directives === []) {
            return [null, null];
        }

        $alternation = implode('|', array_map('preg_quote', array_keys($directives)));

        if (preg_match('/\b(' . $alternation . ')\s*\$?\s*([\d][\d.,]*)/u', $normalized, $matches) !== 1) {
            return [null, null];
        }

        $amount = self::toFloat($matches[2]);

        if ($amount === null) {
            return [null, null];
        }

        return $directives[$matches[1]] === IntentLexiconService::MIN_PRICE
            ? [$amount, null]
            : [null, $amount];
    }

    /**
     * A currency symbol is required here on purpose. Gift queries are full of
     * bare numbers that are not prices — "una mujer de 32 años" would otherwise
     * cap the budget at $32 and return almost nothing.
     */
    private static function matchBareCurrencyAmount(string $normalized): ?float
    {
        return preg_match('/\$\s*([\d][\d.,]*)/u', $normalized, $matches) === 1
            ? self::toFloat($matches[1])
            : null;
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

    /**
     * Assumes en-US grouping ("1,299.99"). A locale that groups the other way
     * round is not distinguishable from this string alone.
     *
     * `Illuminate\Support\Number::parseFloat()` does this properly, locale and
     * all — but it hard-requires the `intl` extension, which this image does not
     * ship. It throws rather than degrading, so adopting it would turn every
     * discovery query into a 500. Swap to it if `intl` ever becomes standard.
     */
    private static function toFloat(string $raw): ?float
    {
        $cleaned = rtrim(str_replace(',', '', $raw), '.');

        return is_numeric($cleaned) ? (float) $cleaned : null;
    }
}
