<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Actions;

use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;

class SetVariantSortingRatingFromCategoryAction
{
    public function __construct(
        protected readonly Variants $variant,
    ) {
    }

    public function execute(): array
    {
        $product = $this->variant->product;
        if (! $product) {
            return [
                'status' => 'skipped',
                'reason' => 'no_product',
                'variant_id' => $this->variant->getId(),
            ];
        }

        $newRating = $this->resolveRating($product);
        $oldRating = (float) $this->variant->rating;

        $status = 'unchanged';

        if ($newRating !== $oldRating) {
            $this->variant->rating = $newRating;
            $this->variant->save();
            $status = 'updated';
        }

        // Propagate variant rating to the product so it lands in the product search index
        $variantRating = (float) $this->variant->rating;
        if ((float) $product->rating !== $variantRating) {
            $product->rating = $variantRating;
            $product->save();
        }

        return [
            'status' => $status,
            'rating' => $newRating,
            'previous' => $oldRating,
            'variant_id' => $this->variant->getId(),
        ];
    }

    private function resolveRating(Products $product): float
    {
        $top = $product->categories()
            ->where('categories.is_deleted', 0)
            ->orderByDesc('categories.weight')
            ->orderByRaw('LOWER(categories.name) ASC')
            ->limit(1)
            ->first(['categories.weight']);

        return $top === null ? 0.0 : (float) $top->weight;
    }
}
