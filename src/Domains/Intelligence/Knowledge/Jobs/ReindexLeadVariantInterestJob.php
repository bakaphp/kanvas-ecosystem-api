<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\RAG\Services\RagComponents;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeEntity;
use Kanvas\Intelligence\Knowledge\Events\KnowledgeIndexRequested;

final class ReindexLeadVariantInterestJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;

    public int $tries = 3;
    public int $uniqueFor = 60;

    public function __construct(public readonly KnowledgeEntity $lead)
    {
    }

    public function handle(): void
    {
        $lead = Lead::query()
            ->where('id', $this->lead->id)
            ->where('apps_id', $this->lead->appId)
            ->where('companies_id', $this->lead->companyId)
            ->notDeleted()
            ->first();

        if ($lead === null) {
            return;
        }

        $this->overwriteAppService($lead->app);
        $lead->searchable();

        if (RagComponents::isEnabled($lead)) {
            KnowledgeIndexRequested::dispatch(KnowledgeEntity::fromModel($lead));
        }
    }

    public function uniqueId(): string
    {
        return $this->lead->key();
    }
}
