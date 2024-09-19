<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Actions;

use Kanvas\Inventory\Channels\Actions\CreatePriceHistoryAction;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Variants\DataTransferObject\VariantChannel as VariantChannelDto;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;

class AddVariantToChannelAction
{
    public function __construct(
        protected VariantsWarehouses $variantsWarehouses,
        protected Channels $channel,
        protected VariantChannelDto $variantChannelDto
    ) {
    }

    public function execute(): VariantsChannels
    {
        $search = [
            'product_variants_warehouse_id' => $this->variantsWarehouses->getId(),
            'channels_id' => $this->channel->getId(),
        ];

        $variantChannel = VariantsChannels::updateOrCreate(
            $search,
            [
                'price' => (float) ($this->variantChannelDto->price ?? 0.00),
                'discounted_price' => (float) ($this->variantChannelDto->discounted_price ?? 0.00),
                'is_published' => $this->variantChannelDto->is_published,
                'products_variants_id' => $this->variantsWarehouses->products_variants_id,
                'warehouses_id' => $this->variantsWarehouses->warehouses_id
            ]
        );

        if ($this->variantChannelDto->price) {
            (new CreatePriceHistoryAction(
                $this->variantsWarehouses,
                $this->channel,
                $variantChannel->price
            ))->execute();
        }

        return $variantChannel;
    }
}
