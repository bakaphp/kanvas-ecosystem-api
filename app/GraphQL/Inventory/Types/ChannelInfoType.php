<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Types;

use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Variants\Models\Variants;

class ChannelInfoType
{
    public function price(Variants $variant, array $request): array
    {
        //$app = app(Apps::class);

        /**
         * @todo allow to change the channel by param or header, to support the multi region
         */
        $defaultChannel = $variant->variantChannels()->first();

        return [
            'price' => $defaultChannel?->price ?? 0,
            'discounted_price' => $defaultChannel?->discounted_price ?? 0,
            'quantity' => 1,
            'is_best_seller' => false,
            'is_on_sale' => false,
            'is_on_promotion' => false,
            'is_coming_soon' => false,
        ];
    }
}
