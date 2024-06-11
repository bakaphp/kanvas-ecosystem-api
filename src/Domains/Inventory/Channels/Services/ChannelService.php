<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Channels\Services;

use Kanvas\Inventory\Channels\Repositories\ChannelRepository;
use Kanvas\Inventory\Variants\DataTransferObject\VariantChannel;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Services\VariantService;
use Kanvas\Inventory\Warehouses\Repositories\WarehouseRepository;

class ChannelService
{
    /**
     * Update data of variant in a variant channel.
     */
    public static function updateChannelVariant(Variants $variant, array $variantsChannels): Variants
    {
        $variant->variantChannels()->forcedelete();
        foreach ($variantsChannels as $variantChannel) {
            $warehouse = WarehouseRepository::getById((int) $variantChannel['warehouses_id']);
            $channel = ChannelRepository::getById((int) $variantChannel['channels_id'],$variant->product->company()->get()->first());
            $variantChannelDto = VariantChannel::from($variantChannel);

            VariantService::addVariantChannel(
                $variant,
                $warehouse,
                $channel,
                $variantChannelDto
            );
        }

        return $variant;
    }

}
