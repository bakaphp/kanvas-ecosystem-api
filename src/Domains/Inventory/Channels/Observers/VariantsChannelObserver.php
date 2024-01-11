<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Channels\Observers;

use Kanvas\Inventory\Channels\Actions\CreatePriceHistoryAction;
use Kanvas\Inventory\Variants\Models\VariantsChannels;

class VariantsChannelObserver
{
    public function saved(VariantsChannels $variantChannel): void
    {
        if ($variantChannel->wasChanged('price')) {
            (new CreatePriceHistoryAction(
                $variantChannel->productVariantWarehouse,
                $variantChannel->channel,
                $variantChannel->price
            ))->execute();
        }
    }
}
