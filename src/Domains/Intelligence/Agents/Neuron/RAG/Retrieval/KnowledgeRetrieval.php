<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\RAG\Retrieval;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Intelligence\Agents\Neuron\RAG\Services\RagComponents;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\Retrieval\SimilarityRetrieval;

class KnowledgeRetrieval implements RetrievalInterface
{
    public function __construct(private readonly ?Model $entity)
    {
    }

    /** @return list<Document> */
    #[Override]
    public function retrieve(Message $query): array
    {
        if (! RagComponents::isEnabled($this->entity)) {
            return [];
        }

        return new SimilarityRetrieval(
            RagComponents::vectorStore($this->entity),
            RagComponents::embeddings($this->entity->app),
        )->retrieve($query);
    }
}
