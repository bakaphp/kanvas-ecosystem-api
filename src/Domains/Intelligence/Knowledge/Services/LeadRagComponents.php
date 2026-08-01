<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Services;

use Baka\Search\SearchEngineResolver;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Knowledge\Enums\LeadRagConfigurationEnum;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Embeddings\GeminiEmbeddingsProvider;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

class LeadRagComponents
{
    public static function embeddings(Apps $app): EmbeddingsProviderInterface
    {
        $dimensions = self::vectorDimension($app);

        return new GeminiEmbeddingsProvider(
            key: (string) $app->get(ConfigurationEnum::GEMINI_KEY->value),
            model: $app->get(LeadRagConfigurationEnum::EMBEDDING_MODEL->value)
                ?? 'gemini-embedding-2',
            config: [
                'outputDimensionality' => $dimensions,
            ],
        );
    }

    public static function vectorStore(Lead $lead): VectorStoreInterface
    {
        $app = $lead->app;
        $configuredCollection = trim(
            (string) $app->get(LeadRagConfigurationEnum::COLLECTION->value)
        );
        $collection = $configuredCollection !== ''
            ? $configuredCollection
            : config('scout.prefix') . 'neuron_lead_knowledge_gemini_' . $app->getId();
        $resultLimit = min(
            max((int) ($app->get(LeadRagConfigurationEnum::RESULT_LIMIT->value) ?? 8), 1),
            20
        );

        return new LeadTypesenseVectorStore(
            client: SearchEngineResolver::getTypesenseClient(
                $app->get('typesense_search_settings') ?? []
            ),
            collection: $collection,
            vectorDimension: self::vectorDimension($app),
            appId: $lead->apps_id,
            companyId: $lead->companies_id,
            leadId: $lead->getId(),
            topK: $resultLimit,
        );
    }

    public static function isEnabled(?Lead $lead): bool
    {
        if ($lead === null) {
            return false;
        }

        return filter_var(
            $lead->app->get(LeadRagConfigurationEnum::ENABLED->value),
            FILTER_VALIDATE_BOOL
        );
    }

    private static function vectorDimension(Apps $app): int
    {
        return max(
            (int) ($app->get(LeadRagConfigurationEnum::VECTOR_DIMENSION->value) ?? 768),
            1
        );
    }
}
