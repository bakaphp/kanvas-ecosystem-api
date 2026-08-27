<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Channels\Observers;

use Kanvas\Inventory\Channels\Actions\CreatePriceHistoryAction;
use Kanvas\Inventory\Variants\Events\VariantSearchDocumentChanged;
use Kanvas\Inventory\Variants\Models\VariantsChannels;

class VariantsChannelObserver
{
    public function saved(VariantsChannels $variantChannel): void
    {
        if ($variantChannel->wasChanged('price')) {
            new CreatePriceHistoryAction(
                $variantChannel->productVariantWarehouse,
                $variantChannel->channel,
                $variantChannel->price,
                auth()->user(),
            )->execute();
        }

        $this->reindexInterests($variantChannel);
    }

    public function deleted(VariantsChannels $variantChannel): void
    {
        $this->reindexInterests($variantChannel);
    }

    private function reindexInterests(VariantsChannels $variantChannel): void
    {
        if ($variantChannel->variant !== null) {
            VariantSearchDocumentChanged::dispatchFor($variantChannel->variant);
        }
    }
}
