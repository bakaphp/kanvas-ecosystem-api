<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Channels\Actions;

use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Channels\Models\VariantChannelPriceHistory;

class CreatePriceHistoryAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        protected VariantsWarehouses $variantsWarehouses,
        protected Channels $channel,
        protected Float $price
    ) {
    }

    /**
     * execute.
     *
     * @return VariantChannelPriceHistory
     */
    public function execute(): VariantChannelPriceHistory
    {
        return VariantChannelPriceHistory::firstOrCreate(
            [
                'product_variants_warehouse_id' => $this->variantsWarehouses->getId(),
                'channels_id' => $this->channel->getId(),
                'products_variants_id' => $this->variantsWarehouses->products_variants_id,
                'price' => $this->price
            ],
            [
                'from_date' => date('Y-m-d H:i:s'),
            ]
        );
    }
}
