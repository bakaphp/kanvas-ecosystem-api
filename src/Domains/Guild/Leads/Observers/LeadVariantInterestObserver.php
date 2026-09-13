<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Observers;

use Kanvas\Guild\Leads\Models\LeadVariantInterest;
use Kanvas\Intelligence\Knowledge\Jobs\ReindexLeadVariantInterestJob;

final class LeadVariantInterestObserver
{
    public function saved(LeadVariantInterest $interest): void
    {
        $this->reindex($interest);

        if ($interest->wasChanged(['leads_id', 'apps_id', 'companies_id'])) {
            ReindexLeadVariantInterestJob::dispatchForLead(
                (int) $interest->getOriginal('leads_id'),
                (int) $interest->getOriginal('apps_id'),
                (int) $interest->getOriginal('companies_id'),
            );
        }
    }

    public function deleted(LeadVariantInterest $interest): void
    {
        $this->reindex($interest);
    }

    private function reindex(LeadVariantInterest $interest): void
    {
        ReindexLeadVariantInterestJob::dispatchForLead(
            (int) $interest->leads_id,
            (int) $interest->apps_id,
            (int) $interest->companies_id,
        );
    }
}
