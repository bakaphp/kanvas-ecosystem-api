<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Listeners;

use Kanvas\Intelligence\Knowledge\Jobs\ReindexVariantInterestLeadsJob;
use Kanvas\Inventory\Variants\Events\VariantSearchDocumentChanged;

final class QueueVariantInterestReindexListener
{
    public function handle(VariantSearchDocumentChanged $event): void
    {
        ReindexVariantInterestLeadsJob::dispatch($event->variantId, $event->appId, $event->companyId)->afterCommit();
    }
}
