<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Channels\Observers;

use Kanvas\Inventory\Channels\Actions\CreatePriceHistoryAction;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Workflow\Enums\WorkflowEnum;

class VariantsChannelObserver
{
    public function saved(VariantsChannels $variantChannel): void
    {
        if ($variantChannel->wasChanged('price')) {
            new CreatePriceHistoryAction(
                $variantChannel->productVariantWarehouse,
                $variantChannel->channel,
                $variantChannel->price,
                auth()->user(),
            )->execute();
        }

        // "Added to the channel" and "updated while already a member" are the same
        // updateOrCreate write on this table (AddVariantToChannelAction) — one fire point covers both.
        $product = $variantChannel->variant?->product;

        $product?->fireWorkflow(WorkflowEnum::VARIANT_CHANNEL_SAVED->value, true, [
            'channel_id' => $variantChannel->channels_id,
            'channel_slug' => $variantChannel->channel?->slug,
            'variant_id' => $variantChannel->products_variants_id,
        ]);
    }
}
