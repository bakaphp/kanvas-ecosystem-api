<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\RAG\Services;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Neuron\RAG\Embeddings\NeuronEmbeddingsAdapter;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeSourceRegistry;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;

class RagComponents
{
    public static function embeddings(Apps $app): EmbeddingsProviderInterface
    {
        return new NeuronEmbeddingsAdapter(KnowledgeComponents::embedder($app));
    }

    public static function isEnabled(?Model $model): bool
    {
        return $model !== null
            && (new KnowledgeSourceRegistry()->for($model::class)?->isEnabledFor($model->app) ?? false);
    }
}
