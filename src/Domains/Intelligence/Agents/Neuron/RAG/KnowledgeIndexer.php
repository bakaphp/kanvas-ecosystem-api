<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\RAG;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeEntity;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeSourceRegistry;

class KnowledgeIndexer
{
    public function __construct(
        private readonly KnowledgeSourceRegistry $sources = new KnowledgeSourceRegistry(),
    ) {
    }

    public function index(Model $model): int
    {
        $entity = KnowledgeEntity::fromModel($model);
        $documents = new KnowledgeDocumentBuilder($this->sources)->build($model);
        $vectorStore = RagComponents::vectorStore($model);
        $embeddedDocuments = RagComponents::embeddings($model->app)->embedDocuments($documents);

        $vectorStore->replaceDocuments($embeddedDocuments, $entity->type, $entity->key());

        return count($documents);
    }
}
