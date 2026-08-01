<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Illuminate\Console\Command;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Knowledge\Services\LeadKnowledgeIndexer;

class IndexLeadKnowledgeCommand extends Command
{
    protected $signature = 'intelligence:lead-rag:index {lead_id : Lead database ID}';
    protected $description = 'Build or replace the Typesense RAG documents for one Lead';

    public function handle(): int
    {
        $lead = Lead::query()
            ->where('id', (int) $this->argument('lead_id'))
            ->where('is_deleted', 0)
            ->firstOrFail();

        $count = new LeadKnowledgeIndexer()->index($lead);
        $this->info("Indexed {$count} knowledge documents for Lead {$lead->getId()}.");

        return self::SUCCESS;
    }
}
