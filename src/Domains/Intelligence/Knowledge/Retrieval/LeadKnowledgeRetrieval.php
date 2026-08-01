<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Retrieval;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Knowledge\Services\LeadRagComponents;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\Retrieval\SimilarityRetrieval;

class LeadKnowledgeRetrieval implements RetrievalInterface
{
    public function __construct(private readonly ?Lead $lead)
    {
    }

    /**
     * @return Document[]
     */
    public function retrieve(Message $query): array
    {
        if (! LeadRagComponents::isEnabled($this->lead)) {
            return [];
        }

        return new SimilarityRetrieval(
            LeadRagComponents::vectorStore($this->lead),
            LeadRagComponents::embeddings($this->lead->app)
        )->retrieve($query);
    }
}
