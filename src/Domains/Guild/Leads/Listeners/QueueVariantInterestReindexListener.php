<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Listeners;

use Kanvas\Guild\Leads\Jobs\ReindexVariantInterestLeadsJob;
use Kanvas\Inventory\Variants\Events\VariantSearchDocumentChanged;

final class QueueVariantInterestReindexListener
{
    public function handle(VariantSearchDocumentChanged $event): void
    {
        ReindexVariantInterestLeadsJob::dispatch(
            $event->variantId,
            $event->appId,
            $event->companyId
        )->afterCommit();
    }
}
