<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadVariantInterest;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeEntity;

final class ReindexVariantInterestLeadsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;

    public int $tries = 3;
    public int $uniqueFor = 60;

    public function __construct(
        public readonly int $variantId,
        public readonly int $appId,
        public readonly int $companyId,
    ) {
    }

    public function handle(): void
    {
        $app = Apps::getById($this->appId);
        $this->overwriteAppService($app);

        LeadVariantInterest::query()
            ->where('variants_id', $this->variantId)
            ->where('apps_id', $this->appId)
            ->where('companies_id', $this->companyId)
            ->where('is_active', true)
            ->where('is_deleted', false)
            ->select(['id', 'leads_id'])
            ->chunkById(250, function ($interests): void {
                $leads = Lead::query()
                    ->whereIn('id', $interests->pluck('leads_id')->unique())
                    ->where('apps_id', $this->appId)
                    ->where('companies_id', $this->companyId)
                    ->notDeleted()
                    ->get();

                foreach ($leads as $lead) {
                    ReindexLeadVariantInterestJob::dispatch(KnowledgeEntity::fromModel($lead));
                }
            });
    }

    public function uniqueId(): string
    {
        return implode(':', [$this->appId, $this->companyId, $this->variantId]);
    }
}
