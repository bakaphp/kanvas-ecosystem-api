<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Types;

use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsChannels;

class ChannelInfoType
{
    /**
     * @todo allow to change the channel by param or header, to support the multi region
     */
    public function price(Variants $variant, array $request): array
    {
        // Resolve the default-channel pricing from the variant's own channel rows in memory.
        // loadMissing is a no-op when the list builder already batch-loaded these (products
        // query), and a single batched load otherwise — so this never fires the per-variant
        // companies/channels/products_variants_channels/warehouse queries that were the N+1.
        $variant->loadMissing([
            'variantChannels.productVariantWarehouse',
            'variantChannels.channel',
        ]);

        $defaultChannelInfo = $variant->variantChannels->first(
            fn (VariantsChannels $variantChannel): bool => $variantChannel->channel !== null
                && (int) $variantChannel->channel->is_default === 1
                && (int) $variantChannel->channel->apps_id === (int) $variant->apps_id
                && (int) $variantChannel->channel->companies_id === (int) $variant->companies_id
        );

        if ($defaultChannelInfo === null) {
            return [
                'price' => 0,
                'discounted_price' => 0,
                'quantity' => 0,
                'is_best_seller' => false,
                'is_on_sale' => false,
                'is_on_promotion' => false,
                'is_coming_soon' => false,
                'config' => null,
            ];
        }

        $warehouseInfo = $defaultChannelInfo->productVariantWarehouse;

        return [
            'price' => $defaultChannelInfo->price ?? 0,
            'discounted_price' => $defaultChannelInfo->discounted_price ?? 0,
            'quantity' => $warehouseInfo?->quantity ?? 0,
            'is_best_seller' => false,
            'is_on_sale' => false,
            'is_on_promotion' => false,
            'is_coming_soon' => false,
            'config' => $defaultChannelInfo->config ?? null,
        ];
    }
}
