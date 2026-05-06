<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Actions;

use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;

class SyncVariantRatingAction
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

        if ($newRating === $oldRating) {
            return [
                'status' => 'unchanged',
                'rating' => $newRating,
                'variant_id' => $this->variant->getId(),
            ];
        }

        $this->variant->rating = $newRating;
        // Use save() not saveQuietly(): Scout's ModelObserver::saved listens on the
        // standard Eloquent saved event, which saveQuietly() suppresses.
        $this->variant->save();

        return [
            'status' => 'updated',
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
