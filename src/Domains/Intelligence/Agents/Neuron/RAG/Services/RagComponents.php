<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\RAG\Services;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Neuron\RAG\Embeddings\NeuronEmbeddingsAdapter;
use Kanvas\Intelligence\Agents\Neuron\RAG\VectorStores\NeuronVectorStoreAdapter;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeEntity;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeScope;
use Kanvas\Intelligence\Knowledge\Enums\KnowledgeConfigurationEnum;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeSourceRegistry;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

/**
 * Neuron-facing resolver: wraps the shared knowledge stack (KnowledgeComponents)
 * in NeuronAI adapters so a Neuron RAG agent gets EmbeddingsProviderInterface /
 * VectorStoreInterface without the Knowledge domain knowing NeuronAI exists.
 */
class RagComponents
{
    public static function embeddings(Apps $app): EmbeddingsProviderInterface
    {
        return new NeuronEmbeddingsAdapter(KnowledgeComponents::embedder($app));
    }

    public static function vectorStore(Model $model): VectorStoreInterface
    {
        /** @var Apps $app */
        $app = $model->app;

        return new NeuronVectorStoreAdapter(
            store: KnowledgeComponents::store($app),
            scope: KnowledgeScope::fromEntity(KnowledgeEntity::fromModel($model)),
            topK: KnowledgeComponents::resultLimit($app),
            minScore: KnowledgeComponents::minScore($app),
        );
    }

    public static function isEnabled(?Model $model): bool
    {
        return $model !== null
            && new KnowledgeSourceRegistry()->for($model::class) !== null
            && filter_var(
                $model->app->get(KnowledgeConfigurationEnum::ENABLED->value),
                FILTER_VALIDATE_BOOL,
            );
    }
}
