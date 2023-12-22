<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Actions;

use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Variants\DataTransferObject\VariantChannel;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;

class AddVariantToChannelAction
{
    public function __construct(
        protected VariantsWarehouses $variantsWarehouses,
        protected Channels $channel,
        protected VariantChannel $variantChannel
    ) {
    }

    public function execute(): Variants
    {
        $channelId = $this->channel->getId();
        $relationship = $this->variantsWarehouses->channels()->find($channelId);

        if ($relationship) {
            // Update existing pivot table values
            $this->variantsWarehouses->channels()->updateExistingPivot($channelId, [
                'price' => $this->variantChannel->price,
                'discounted_price' => $this->variantChannel->discounted_price,
                'is_published' => $this->variantChannel->is_published,
                'products_variants_id' => $this->variantsWarehouses->products_variants_id,
                'product_variants_warehouse_id' => $this->variantsWarehouses->getId(),
                'warehouses_id' => $this->variantsWarehouses->warehouses_id,
            ]);
        } else {
            // Create new relationship if it doesn't exist
            $this->variantsWarehouses->channels()->attach($channelId, [
                'price' => $this->variantChannel->price,
                'discounted_price' => $this->variantChannel->discounted_price,
                'is_published' => $this->variantChannel->is_published,
                'products_variants_id' => $this->variantsWarehouses->products_variants_id,
                'product_variants_warehouse_id' => $this->variantsWarehouses->getId(),
                'warehouses_id' => $this->variantsWarehouses->warehouses_id,
            ]);
        }

        return $this->variantsWarehouses->variant;
    }
}
