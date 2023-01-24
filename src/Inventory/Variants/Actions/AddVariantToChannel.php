<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Actions;

use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Variants\DataTransferObject\VariantChannel;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

class AddVariantToChannel
{
    public function __construct(
        protected Variants $variants,
        protected Channels $channel,
        protected Warehouses $warehouse,
        protected VariantChannel $variantChannel
    ) {
    }

    public function execute(): Variants
    {
        if ($this->variants->channels()->find($this->channel->getId())) {
            $this->variants->channels()->syncWithoutDetaching([
                $this->channel->getId() => [
                    'warehouses_id' => $this->warehouse->getId(),
                    'price' => $this->variantChannel->price,
                    'discounted_price' => $this->variantChannel->discounted_price,
                    'is_published' => $this->variantChannel->is_published,
                ]
            ]);
        } else {
            $this->variants->channels()->attach([
                $this->channel->getId() => [
                    'warehouses_id' => $this->warehouse->getId(),
                    'price' => $this->variantChannel->price,
                    'discounted_price' => $this->variantChannel->discounted_price,
                    'is_published' => $this->variantChannel->is_published,
                ]
            ]);
        }
        return $this->variants;
    }
}
