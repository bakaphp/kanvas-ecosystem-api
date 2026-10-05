<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\RAG\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Neuron\RAG\Embeddings\NeuronEmbeddingsAdapter;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;

class RagComponents
{
    public static function embeddings(Apps $app): EmbeddingsProviderInterface
    {
        return new NeuronEmbeddingsAdapter(KnowledgeComponents::embedder($app));
    }
}
