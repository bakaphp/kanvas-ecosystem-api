<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\RAG;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeDocument;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeEntity;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeSourceRegistry;
use NeuronAI\RAG\DataLoader\StringDataLoader;
use NeuronAI\RAG\Document;
use RuntimeException;

class KnowledgeDocumentBuilder
{
    public function __construct(
        private readonly KnowledgeSourceRegistry $sources = new KnowledgeSourceRegistry(),
    ) {
    }

    /** @return list<Document> */
    public function build(Model $model): array
    {
        $source = $this->sources->for($model::class)
            ?? throw new RuntimeException('No knowledge source is registered for ' . $model::class . '.');
        $entity = KnowledgeEntity::fromModel($model);

        return collect($source->build($model))
            ->flatMap(fn (KnowledgeDocument $document): array => $this->toNeuronDocuments($entity, $document))
            ->values()
            ->all();
    }

    /** @return list<Document> */
    private function toNeuronDocuments(KnowledgeEntity $entity, KnowledgeDocument $source): array
    {
        $documents = StringDataLoader::for($source->content)->getDocuments();

        foreach ($documents as $index => $document) {
            $document->id = $source->id . '-' . $index;
            $document->sourceType = $entity->type;
            $document->sourceName = $entity->key();

            foreach ($source->metadata as $name => $value) {
                $document->addMetadata($name, $value);
            }
        }

        return $documents;
    }
}
