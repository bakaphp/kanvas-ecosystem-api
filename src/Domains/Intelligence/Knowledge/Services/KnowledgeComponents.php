<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Services;

use Baka\Search\SearchEngineResolver;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Knowledge\Contracts\KnowledgeEmbedder;
use Kanvas\Intelligence\Knowledge\Embedders\LaravelAiKnowledgeEmbedder;
use Kanvas\Intelligence\Knowledge\Enums\KnowledgeConfigurationEnum;
use Kanvas\Intelligence\Knowledge\VectorStores\KnowledgeVectorStore;
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
            client: SearchEngineResolver::typesenseClient($app),
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
        return self::clampedInt($app, KnowledgeConfigurationEnum::RESULT_LIMIT, default: 8, max: 20);
    }

    private static function clampedInt(Apps $app, KnowledgeConfigurationEnum $key, int $default, int $max = PHP_INT_MAX): int
    {
        return min(max((int) $app->get($key->value, $default), 1), $max);
    }

    /**
     * On unless the tenant opts out, and only where there is somewhere to write: an app without
     * Typesense credentials would fail every turn instead of remembering. Each qualifying turn of a
     * remembering agent costs one embedding call on the app's own key.
     */
    public static function memoryEnabled(Apps $app): bool
    {
        return $app->getBool(KnowledgeConfigurationEnum::AGENT_MEMORY_ENABLED->value, default: true)
            && SearchEngineResolver::hasTypesenseCredentials(SearchEngineResolver::typesenseSettings($app));
    }

    public static function knowledgeEnabled(Apps $app): bool
    {
        return $app->getBool(KnowledgeConfigurationEnum::ENABLED->value);
    }

    public static function memoryStore(Apps $app): KnowledgeVectorStore
    {
        return new KnowledgeVectorStore(self::store($app), self::memoryResultLimit($app));
    }

    /** On top of the knowledge result limit, never in its place. */
    public static function memoryResultLimit(Apps $app): int
    {
        return self::clampedInt($app, KnowledgeConfigurationEnum::AGENT_MEMORY_RESULT_LIMIT, default: 4, max: 10);
    }

    public static function memoryIngestMinChars(Apps $app): int
    {
        return self::clampedInt($app, KnowledgeConfigurationEnum::AGENT_MEMORY_INGEST_MIN_CHARS, default: 80);
    }

    public static function memoryRetentionDays(Apps $app): int
    {
        return self::clampedInt($app, KnowledgeConfigurationEnum::AGENT_MEMORY_RETENTION_DAYS, default: 365);
    }

    /** Optional similarity floor; null when the app hasn't configured one (no filtering). */
    public static function minScore(Apps $app): ?float
    {
        $value = $app->get(KnowledgeConfigurationEnum::MIN_SCORE->value);

        return is_numeric($value) ? (float) $value : null;
    }
}
