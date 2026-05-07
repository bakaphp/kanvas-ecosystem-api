<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Channels\Actions;

use Illuminate\Support\Collection;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsChannels;

class UnPublishAllVariantsAction
{
    public function __construct(
        protected Channels $channel,
    ) {
    }

    public function execute(): void
    {
        $dontUnPublishVariantsId = $this->channel->company->get('dont_unpublish_variants', []);

        $query = $this->channel->availableProducts()->where('is_published', 1);

        if (! empty($dontUnPublishVariantsId)) {
            $query->whereNotIn('products_variants_id', $dontUnPublishVariantsId);
        }

        // Get variant and product IDs in a single query
        $channelVariants = $query->select('products_variants_id')->distinct()->pluck('products_variants_id');

        if ($channelVariants->isEmpty()) {
            return;
        }

        $productIds = Variants::whereIn('id', $channelVariants)
            ->distinct()
            ->pluck('products_id');

        // Bulk update: unpublish all channel records
        $query->update(['is_published' => 0]);

        // Batch remove variants from search index
        Variants::whereIn('id', $channelVariants)
            ->chunkById(500, function (Collection $variants): void {
                $variants->unsearchable();
            });

        // Find products that still have published variants in this channel
        $productsWithPublished = VariantsChannels::where('channels_id', $this->channel->getId())
            ->where('is_published', 1)
            ->whereIn('products_variants_id', function ($sub) use ($productIds): void {
                $sub->select('id')
                    ->from('products_variants')
                    ->whereIn('products_id', $productIds);
            })
            ->distinct()
            ->pluck('products_variants_id');

        $productIdsStillPublished = $productsWithPublished->isNotEmpty()
            ? Variants::whereIn('id', $productsWithPublished)->distinct()->pluck('products_id')
            : collect();

        $productIdsToUnpublish = $productIds->diff($productIdsStillPublished);

        if ($productIdsToUnpublish->isNotEmpty()) {
            // Bulk unpublish products
            Products::whereIn('id', $productIdsToUnpublish)->update(['is_published' => 0]);

            // Batch remove products from search index
            Products::whereIn('id', $productIdsToUnpublish)
                ->chunkById(500, function (Collection $products): void {
                    $products->unsearchable();
                });
        }
    }
}
