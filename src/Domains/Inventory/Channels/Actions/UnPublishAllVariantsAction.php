<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Channels\Actions;

use Kanvas\Inventory\Channels\Models\Channels;

/**
 * Unpublishes every variant in the channel. To drop only what a feed no longer sends, import
 * through a run and finish it instead (FinishProductImportAction).
 */
class UnPublishAllVariantsAction
{
    public function __construct(
        protected Channels $channel,
    ) {
    }

    public function execute(): void
    {
        $dontUnPublishVariantsId = $this->channel->dontUnpublishVariantIds();

        $query = $this->channel->availableProducts()->where('is_published', 1);

        if (! empty($dontUnPublishVariantsId)) {
            $query->whereNotIn('products_variants_id', $dontUnPublishVariantsId);
        }

        $channelVariants = $query->select('products_variants_id')->distinct()->pluck('products_variants_id');

        new UnPublishChannelVariantsAction($this->channel, $channelVariants)->execute();
    }
}
