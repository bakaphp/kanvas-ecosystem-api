<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Services;

use Baka\Search\SearchEngineResolver;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Knowledge\Contracts\KnowledgeEmbedder;
use Kanvas\Intelligence\Knowledge\Embedders\LaravelAiKnowledgeEmbedder;
use Kanvas\Intelligence\Knowledge\Enums\KnowledgeConfigurationEnum;
use Kanvas\Intelligence\Knowledge\VectorStores\TypesenseKnowledgeStore;

/**
 * Neutral per-app resolver for the shared knowledge stack. Both bindings — the
 * Neuron adapters and the Laravel tool — build their store/embedder/indexer
 * from here so config resolution lives in exactly one place. The default
 * collection name keeps the legacy Neuron name so existing Lead RAG indexes
 * are reused with no migration.
 */
final class KnowledgeComponents
{
    /**
     * @param string|null $collection a dedicated collection for a consumer whose vectors must never surface
     *                                in agent knowledge retrieval (e.g. WordPress duplicate detection)
     */
    public static function store(Apps $app, ?KnowledgeEmbedder $embedder = null, ?string $collection = null): TypesenseKnowledgeStore
    {
        $configuredCollection = trim((string) ($collection ?? $app->get(KnowledgeConfigurationEnum::COLLECTION->value)));

        return new TypesenseKnowledgeStore(
            client: SearchEngineResolver::getTypesenseClient($app->get('typesense_search_settings') ?? []),
            collection: $configuredCollection !== ''
                ? $configuredCollection
                : config('scout.prefix') . 'neuron_lead_knowledge_gemini_' . $app->getId(),
            vectorDimension: ($embedder ?? self::embedder($app))->dimension(),
        );
    }

    public static function embedder(Apps $app): KnowledgeEmbedder
    {
        return new LaravelAiKnowledgeEmbedder($app);
    }

    public static function indexer(Apps $app): KnowledgeIndexer
    {
        $embedder = self::embedder($app);

        return new KnowledgeIndexer(self::store($app, $embedder), $embedder);
    }

    public static function resultLimit(Apps $app): int
    {
        return min(max((int) ($app->get(KnowledgeConfigurationEnum::RESULT_LIMIT->value) ?? 8), 1), 20);
    }

    /** Optional similarity floor; null when the app hasn't configured one (no filtering). */
    public static function minScore(Apps $app): ?float
    {
        $value = $app->get(KnowledgeConfigurationEnum::MIN_SCORE->value);

        return is_numeric($value) ? (float) $value : null;
    }
}
