<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Variants\Actions;

use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Inventory\Variants\DataTransferObject\VariantChannel;

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
        if ($this->variants->channels()->find($this->channel->id)) {
            $this->variants->channels()->syncWithoutDetaching([
                $this->channel->id => [
                    'warehouses_id' => $this->warehouse->id,
                    'price' => $this->variantChannel->price,
                    'discounted_price' => $this->variantChannel->discounted_price,
                    'is_published' => $this->variantChannel->is_published,
                ]
            ]);
        } else {
            $this->variants->channels()->attach([
                $this->channel->id => [
                    'warehouses_id' => $this->warehouse->id,
                    'price' => $this->variantChannel->price,
                    'discounted_price' => $this->variantChannel->discounted_price,
                    'is_published' => $this->variantChannel->is_published,
                ]
            ]);
        }
        return $this->variants;
    }
}
