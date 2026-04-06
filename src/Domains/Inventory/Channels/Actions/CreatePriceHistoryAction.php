<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Channels\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Channels\Models\VariantChannelPriceHistory;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;

class CreatePriceHistoryAction
{
    public function __construct(
        protected VariantsWarehouses $variantsWarehouses,
        protected Channels $channel,
        protected float $price,
        protected ?UserInterface $user = null,
    ) {
    }

    public function execute(): VariantChannelPriceHistory
    {
        return VariantChannelPriceHistory::create([
            'product_variants_warehouse_id' => $this->variantsWarehouses->getId(),
            'channels_id' => $this->channel->getId(),
            'products_variants_id' => $this->variantsWarehouses->products_variants_id,
            'price' => $this->price,
            'from_date' => date('Y-m-d H:i:s'),
            'users_id' => $this->user?->getId() ?? 0,
        ]);
    }
}
