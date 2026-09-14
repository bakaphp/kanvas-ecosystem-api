<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\DataTransferObject;

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
    public function __construct(
        public readonly string $sentence,
        public readonly ?float $minPrice = null,
        public readonly ?float $maxPrice = null,
        public readonly bool $inStockOnly = false,
        public readonly ?AudienceEnum $audience = null,
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
            audience: $lexicon->matchAudience($normalized),
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
     * Currency symbol required: "una mujer de 32 años" would otherwise cap the budget at $32.
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
     * Assumes en-US grouping ("1,299.99"). Number::parseFloat() does this properly
     * but hard-requires `intl`, which this image lacks — it throws, so it would
     * 500 every query. Swap to it if `intl` ever becomes standard.
     */
    private static function toFloat(string $raw): ?float
    {
        $cleaned = rtrim(str_replace(',', '', $raw), '.');

        return is_numeric($cleaned) ? (float) $cleaned : null;
    }
}
