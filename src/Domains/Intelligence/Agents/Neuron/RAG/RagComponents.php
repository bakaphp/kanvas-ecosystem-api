<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\RAG;

use Baka\Search\SearchEngineResolver;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeEntity;
use Kanvas\Intelligence\Knowledge\Enums\LeadRagConfigurationEnum;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeSourceRegistry;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Embeddings\GeminiEmbeddingsProvider;

class RagComponents
{
    public static function embeddings(Apps $app): EmbeddingsProviderInterface
    {
        return new GeminiEmbeddingsProvider(
            key: (string) $app->get(ConfigurationEnum::GEMINI_KEY->value),
            model: $app->get(LeadRagConfigurationEnum::EMBEDDING_MODEL->value) ?? 'gemini-embedding-2',
            config: ['outputDimensionality' => self::vectorDimension($app)],
        );
    }

    public static function vectorStore(Model $model): TypesenseVectorStore
    {
        /** @var Apps $app */
        $app = $model->app;
        $configuredCollection = trim((string) $app->get(LeadRagConfigurationEnum::COLLECTION->value));

        return new TypesenseVectorStore(
            client: SearchEngineResolver::getTypesenseClient($app->get('typesense_search_settings') ?? []),
            collection: $configuredCollection !== ''
                ? $configuredCollection
                : config('scout.prefix') . 'neuron_lead_knowledge_gemini_' . $app->getId(),
            vectorDimension: self::vectorDimension($app),
            entity: KnowledgeEntity::fromModel($model),
            topK: min(max((int) ($app->get(LeadRagConfigurationEnum::RESULT_LIMIT->value) ?? 8), 1), 20),
        );
    }

    public static function isEnabled(?Model $model): bool
    {
        return $model !== null
            && new KnowledgeSourceRegistry()->for($model::class) !== null
            && filter_var(
                $model->app->get(LeadRagConfigurationEnum::ENABLED->value),
                FILTER_VALIDATE_BOOL,
            );
    }

    private static function vectorDimension(Apps $app): int
    {
        return max((int) ($app->get(LeadRagConfigurationEnum::VECTOR_DIMENSION->value) ?? 768), 1);
    }
}
