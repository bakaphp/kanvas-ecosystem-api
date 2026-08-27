<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Observers;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadVariantInterest;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeEntity;
use Kanvas\Intelligence\Knowledge\Jobs\ReindexLeadVariantInterestJob;

final class LeadVariantInterestObserver
{
    public function saved(LeadVariantInterest $interest): void
    {
        $this->reindex($interest);

        if ($interest->wasChanged(['leads_id', 'apps_id', 'companies_id'])) {
            $this->reindexByTenant(
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
        $lead = $interest->relationLoaded('lead')
            ? $interest->lead
            : $this->findLead(
                (int) $interest->leads_id,
                (int) $interest->apps_id,
                (int) $interest->companies_id,
            );

        if ($lead !== null) {
            ReindexLeadVariantInterestJob::dispatch(KnowledgeEntity::fromModel($lead))->afterCommit();
        }
    }

    private function reindexByTenant(int $leadId, int $appId, int $companyId): void
    {
        $lead = $this->findLead($leadId, $appId, $companyId);
        if ($lead !== null) {
            ReindexLeadVariantInterestJob::dispatch(KnowledgeEntity::fromModel($lead))->afterCommit();
        }
    }

    private function findLead(int $leadId, int $appId, int $companyId): ?Lead
    {
        return Lead::query()
            ->where('id', $leadId)
            ->where('apps_id', $appId)
            ->where('companies_id', $companyId)
            ->notDeleted()
            ->first();
    }
}
