<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Discovery;

use Kanvas\Inventory\Recommendations\DataTransferObject\ProductIntent;
use Kanvas\Inventory\Recommendations\Services\IntentLexiconService;

/**
 * Every budget, numeric bound and category is checked on the rendered result, the
 * same way whether it came from a search document or the database. An engine's
 * product-level ranges can admit a product whose cheap variant and low-mileage
 * variant are different cars; here a product survives only through the variants
 * that satisfy ALL limits together, and only those variants are returned.
 */
final class HardConstraintVerifier
{
    public function __construct(
        private readonly ProductIntent $intent,
        private readonly NumericAttributes $numericAttributes,
    ) {
    }

    /**
     * @param array{product: array, variants: array} $result
     * @param callable(int $variantId, string $attribute): mixed $numericValue the raw value of
     *        a numeric attribute for a variant, falling back to the product's own
     *
     * @return array{product: array, variants: array}|null the result keeping only qualifying variants
     */
    public function filter(array $result, callable $numericValue): ?array
    {
        if (! $this->intent->hasHardConstraints()) {
            return $result;
        }

        if (! $this->inCategory($result['product']['categories'] ?? [])) {
            return null;
        }

        $anyPriced = false;
        foreach ($result['variants'] as $variant) {
            $anyPriced = $anyPriced || $this->priceOf($variant) !== null;
        }

        $eligible = array_values(array_filter(
            $result['variants'],
            fn (array $variant): bool => $this->withinBudget($variant, $anyPriced)
                && $this->withinNumericBounds((int) $variant['id'], $numericValue),
        ));

        if ($eligible === []) {
            return null;
        }

        $result['variants'] = $eligible;

        return $result;
    }

    private function inCategory(array $categories): bool
    {
        if ($this->intent->category === null) {
            return true;
        }

        $wanted = IntentLexiconService::normalize($this->intent->category);

        foreach ($categories as $category) {
            if (IntentLexiconService::normalize((string) ($category['name'] ?? '')) === $wanted) {
                return true;
            }
        }

        return false;
    }

    /**
     * An unpriced variant can't be shown to break a budget, so it qualifies — but
     * only when the product has no priced variant at all; otherwise the priced
     * ones decide.
     */
    private function withinBudget(array $variant, bool $anyPriced): bool
    {
        if (! $this->intent->hasPriceConstraint()) {
            return true;
        }

        $price = $this->priceOf($variant);

        if ($price === null) {
            return ! $anyPriced;
        }

        return ($this->intent->minPrice === null || $price >= $this->intent->minPrice)
            && ($this->intent->maxPrice === null || $price <= $this->intent->maxPrice);
    }

    /** No value means the bound can't be shown to hold, so it fails. */
    private function withinNumericBounds(int $variantId, callable $numericValue): bool
    {
        foreach ($this->intent->numericFilters as $filter) {
            $attribute = $this->numericAttributes->attributeFor($filter->field);

            if ($attribute === null) {
                return false;
            }

            $value = $this->numericAttributes->parseValue($filter->field, $numericValue($variantId, $attribute));

            if (! $filter->admits($value)) {
                return false;
            }
        }

        return true;
    }

    private function priceOf(array $variant): ?float
    {
        $price = $variant['channel']['price'] ?? null;

        return $price !== null && (float) $price > 0 ? (float) $price : null;
    }
}
