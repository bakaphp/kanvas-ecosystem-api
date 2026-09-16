<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Observers;

use Kanvas\Inventory\Products\Events\ProductUpdateEvent;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Workflow\Enums\WorkflowEnum;

class ProductsObserver
{
    public function saved(Products $product): void
    {
        if ($product->wasChanged('products_types_id') && $product->productsTypes()->exists()) {
            $product->productsTypes->setTotalProducts();
        }

        $product->clearLightHouseCache(withKanvasConfiguration: false);
        $product->setTotalVariants();

        $product->recalculateWeightByImageCount();

        // A plain Product save carries no channel context on its own — notify per-channel with the
        // same flat shape VariantsChannelObserver fires on a `products_variants_channels` row save,
        // so one Rule (`channel_id == X`) can react to either "added to the channel" or "the product
        // itself changed while already a member" without a second trigger or condition.
        foreach ($product->activeChannelMemberships() as $membership) {
            $product->fireWorkflow(WorkflowEnum::VARIANT_CHANNEL_SAVED->value, true, [
                'channel_id' => $membership['channel_id'],
                'channel_slug' => $membership['channel_slug'],
            ]);
        }
    }

    public function updating(Products $product): void
    {
        if ($product->isDirty('users_id')) {
            $product->users_id = $product->getOriginal('users_id');
        }
    }

    public function updated(Products $product): void
    {
        ProductUpdateEvent::dispatch($product);
    }
}
