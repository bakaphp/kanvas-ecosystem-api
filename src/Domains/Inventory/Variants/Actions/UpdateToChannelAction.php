<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Actions;

use Kanvas\Inventory\Variants\DataTransferObject\VariantChannel as VariantChannelDto;
use Kanvas\Inventory\Variants\Models\VariantsChannels;

class UpdateToChannelAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public VariantsChannels $variantsChannels,
        public VariantChannelDto $variantsChannelsDto,
    ) {
        // Set default values in the DTO
        $variantsChannelsDto->price = $variantsChannelsDto->price ?? $variantsChannels->price; // Set your default value
        $variantsChannelsDto->discounted_price = $variantsChannelsDto->discounted_price ?? $variantsChannels->discounted_price;
        $variantsChannelsDto->is_published = $variantsChannelsDto->is_published ?? $variantsChannels->is_published;
        $this->variantsChannelsDto = $variantsChannelsDto;
    }

    /**
     * execute.
     * @psalm-suppress ArgumentTypeCoercion
     */
    public function execute(): VariantsChannels
    {
        $this->variantsChannels->update(
            [
                'price' => $this->variantsChannelsDto->price,
                'discounted_price' => $this->variantsChannelsDto->discounted_price,
                'is_published' => $this->variantsChannelsDto->is_published,
            ]
        );

        return $this->variantsChannels;
    }
}
