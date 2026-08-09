<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\RAG\Embeddings;

use Kanvas\Intelligence\Knowledge\Contracts\KnowledgeEmbedder;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use Override;

/**
 * Bridges NeuronAI's EmbeddingsProviderInterface onto the shared, neutral
 * KnowledgeEmbedder so the Neuron RAG path and the Laravel tool embed through
 * one implementation (identical vectors within a collection).
 */
final class NeuronEmbeddingsAdapter implements EmbeddingsProviderInterface
{
    public function __construct(private readonly KnowledgeEmbedder $embedder)
    {
    }

    #[Override]
    public function embedText(string $text): array
    {
        return $this->embedder->embed($text);
    }

    #[Override]
    public function embedDocument(Document $document): Document
    {
        $document->embedding = $this->embedder->embed($document->getContent());

        return $document;
    }

    #[Override]
    public function embedDocuments(array $documents): array
    {
        $documents = array_values($documents);
        $vectors = $this->embedder->embedBatch(
            array_map(static fn (Document $document): string => $document->getContent(), $documents),
        );

        foreach ($documents as $index => $document) {
            $document->embedding = $vectors[$index] ?? [];
        }

        return $documents;
    }
}
