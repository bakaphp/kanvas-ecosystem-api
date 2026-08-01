<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Services;

use Kanvas\Guild\Leads\Models\Lead;

class LeadKnowledgeIndexer
{
    public function index(Lead $lead): int
    {
        // @todo Route through a multi-entity document builder registry when RAG expands beyond Lead.
        $documents = new LeadKnowledgeDocumentBuilder()->build($lead);
        $vectorStore = LeadRagComponents::vectorStore($lead);
        $sourceName = sprintf('company-%d-lead-%d', $lead->companies_id, $lead->getId());
        $vectorStore->deleteBy('lead', $sourceName);
        $vectorStore->addDocuments(
            LeadRagComponents::embeddings($lead->app)->embedDocuments($documents)
        );

        return count($documents);
    }
}
