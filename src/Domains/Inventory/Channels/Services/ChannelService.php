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
        // Fetch existing config values before deleting records
        $existingConfigs = [];
        foreach ($variant->variantChannels as $existingVariantChannel) {
            $key = $existingVariantChannel->warehouses_id . '-' . $existingVariantChannel->channels_id;
            $existingConfigs[$key] = $existingVariantChannel->config;
        }

        // Delete old records
        $variant->variantChannels()->forcedelete();

        foreach ($variantsChannels as $variantChannel) {
            $warehouse = WarehouseRepository::getById((int) $variantChannel['warehouses_id']);
            $channel = ChannelRepository::getById((int) $variantChannel['channels_id'], $variant->product->company()->get()->first());

            // Check if the 'config' key exists in the input array
            $key = $warehouse->getId() . '-' . $channel->getId();
            if (! array_key_exists('config', $variantChannel) && isset($existingConfigs[$key])) {
                // Retain the existing config value if 'config' is not provided in the input array
                $variantChannel['config'] = $existingConfigs[$key];
            }

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
