<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Actions;

use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Variants\DataTransferObject\VariantChannel;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;

class AddVariantToChannel
{
    public function __construct(
        protected VariantsWarehouses $variantsWarehouses,
        protected Channels $channel,
        protected VariantChannel $variantChannel
    ) {
    }

    public function execute(): Variants
    {
        if ($this->variantsWarehouses->channels()->find($this->channel->getId())) {
            $this->variantsWarehouses->channels()->syncWithoutDetaching([
                $this->channel->getId() => [
                    'price' => $this->variantChannel->price,
                    'discounted_price' => $this->variantChannel->discounted_price,
                    'is_published' => $this->variantChannel->is_published,
                    'products_variants_id' => $this->variantsWarehouses->products_variants_id,
                    'warehouses_id' => $this->variantsWarehouses->warehouses_id,
                ]
            ]);
        } else {
            $this->variantsWarehouses->channels()->attach([
                $this->channel->getId() => [
                    'price' => $this->variantChannel->price,
                    'discounted_price' => $this->variantChannel->discounted_price,
                    'is_published' => $this->variantChannel->is_published,
                    'products_variants_id' => $this->variantsWarehouses->products_variants_id,
                    'warehouses_id' => $this->variantsWarehouses->warehouses_id,
                ]
            ]);
        }
        return $this->variantsWarehouses->variant;
    }
}
