<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Types;

use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Variants\Models\Variants;

class ChannelInfoType
{
    public function price(Variants $variant, array $request): array
    {
        //$app = app(Apps::class);

        /**
         * @todo allow to change the channel by param or header, to support the multi region
         */
        $defaultChannel = Channels::getDefault($variant->company, $variant->app);
        $defaultChannelInfo = $variant->variantChannels()->where('channels_id', $defaultChannel->getId())->first();
        $warehouseInfo = $defaultChannelInfo?->productVariantWarehouse()->first();

        return [
            'price' => $defaultChannelInfo?->price ?? 0,
            'discounted_price' => $defaultChannelInfo?->discounted_price ?? 0,
            'quantity' => $warehouseInfo?->quantity ?? 0,
            'is_best_seller' => false,
            'is_on_sale' => false,
            'is_on_promotion' => false,
            'is_coming_soon' => false,
        ];
    }
}
