<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Channels\Actions;

use Illuminate\Support\Collection;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsChannels;

/**
 * Unpublishes the given variants in one channel, drops them from the search index, and
 * unpublishes any product left with no published variant in that channel.
 */
class UnPublishChannelVariantsAction
{
    /**
     * @param Collection<int, int> $variantIds
     */
    public function __construct(
        protected Channels $channel,
        protected Collection $variantIds,
    ) {
    }

    public function execute(): void
    {
        if ($this->variantIds->isEmpty()) {
            return;
        }

        $productIds = Variants::whereIn('id', $this->variantIds)
            ->distinct()
            ->pluck('products_id');

        $this->variantIds->chunk(1000)->each(
            fn (Collection $ids) => VariantsChannels::where('channels_id', $this->channel->getId())
                ->where('is_published', 1)
                ->whereIn('products_variants_id', $ids)
                ->update(['is_published' => 0])
        );

        Variants::whereIn('id', $this->variantIds)
            ->chunkById(500, function (Collection $variants): void {
                $variants->unsearchable();
            });

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

        if ($productIdsToUnpublish->isEmpty()) {
            return;
        }

        Products::whereIn('id', $productIdsToUnpublish)->update(['is_published' => 0]);

        Products::whereIn('id', $productIdsToUnpublish)
            ->chunkById(500, function (Collection $products): void {
                $products->unsearchable();
            });
    }
}
