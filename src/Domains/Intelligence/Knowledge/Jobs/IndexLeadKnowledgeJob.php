<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Knowledge\Services\LeadKnowledgeIndexer;

class IndexLeadKnowledgeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public bool $afterCommit = true;
    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(public readonly Lead $lead)
    {
    }

    public function handle(): void
    {
        // A concrete model is safe here: SerializesModels restores it before rebuilding documents.
        new LeadKnowledgeIndexer()->index($this->lead);
    }
}
