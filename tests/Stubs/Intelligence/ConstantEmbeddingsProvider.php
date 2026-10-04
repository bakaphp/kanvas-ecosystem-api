<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;

/**
 * One vector for every text, so every stored document scores the same against every query and a test
 * proves scoping by the filter, never by similarity.
 */
final class ConstantEmbeddingsProvider implements EmbeddingsProviderInterface
{
    public const array VECTOR = [0.1, 0.2, 0.3, 0.4];

    public function embedText(string $text): array
    {
        return self::VECTOR;
    }

    public function embedDocument(Document $document): Document
    {
        return $document->setEmbedding(self::VECTOR);
    }

    public function embedDocuments(array $documents): array
    {
        return array_map($this->embedDocument(...), $documents);
    }
}
