<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Baka\Search\SearchEngineResolver;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Typesense\Client;
use Typesense\Exceptions\ObjectNotFound;

/**
 * Shared Typesense-backed knowledge store for agent RAG. Framework-agnostic on
 * purpose: any backend (Laravel-AI tool, Neuron vector store, ingestion job)
 * can read/write the same per-app collection using the same document shape
 * (content/sourceType/sourceName/embedding), so switching an agent's provider
 * never loses what was already indexed.
 */
class AgentTypesenseKnowledgeService
{
    public function __construct(protected readonly Apps $app)
    {
    }

    public function client(): Client
    {
        return SearchEngineResolver::getTypesenseClient(
            $this->app->get('typesense_search_settings') ?? [],
        );
    }

    public function collection(): string
    {
        $collection = $this->app->get(ConfigurationEnum::TYPESENSE_VECTOR_COLLECTION->value);

        return is_string($collection) && $collection !== ''
            ? $collection
            : "agent_knowledge_app_{$this->app->getId()}";
    }

    public function dimension(): int
    {
        $dimension = $this->app->get(ConfigurationEnum::TYPESENSE_VECTOR_DIMENSION->value);

        return is_numeric($dimension) ? (int) $dimension : 1536;
    }

    /**
     * Embed text using this tenant's own OpenAI key (mirrors Neuron's
     * BaseAgent::embeddings()) instead of the single global key in
     * config/ai.php. Registers a per-app named laravel/ai provider — AiManager
     * caches resolved providers by name on its singleton, so the name must
     * stay app-specific to avoid one tenant's key leaking into another's
     * requests.
     *
     * @return array<int, float>
     */
    public function embed(string $text): array
    {
        return Str::of($text)->toEmbeddings($this->embeddingProviderName());
    }

    private function embeddingProviderName(): string
    {
        $name = "openai_agent_knowledge_app_{$this->app->getId()}";

        config(["ai.providers.{$name}" => [
            'driver' => 'openai',
            'key' => $this->app->get(ConfigurationEnum::OPEN_AI_EMBEDDINGS_KEY->value)
                ?? config('ai.providers.openai.key'),
        ]]);

        return $name;
    }

    /**
     * @param array<int, float> $embedding
     * @return array<int, array{content: string, sourceType: ?string, sourceName: ?string, score: ?float}>
     */
    public function search(array $embedding, int $topK = 4): array
    {
        $client = $this->client();
        $collection = $this->collection();

        if (! $this->collectionExists($client, $collection)) {
            return [];
        }

        $response = $client->multiSearch->perform([
            'searches' => [[
                'collection' => $collection,
                'q' => '*',
                'vector_query' => 'embedding:(' . json_encode($embedding) . ', k:' . $topK . ')',
                'exclude_fields' => 'embedding',
                'per_page' => $topK,
            ]],
        ]);

        $hits = $response['results'][0]['hits'] ?? [];

        return array_map(
            static fn (array $hit): array => [
                'content' => $hit['document']['content'] ?? '',
                'sourceType' => $hit['document']['sourceType'] ?? null,
                'sourceName' => $hit['document']['sourceName'] ?? null,
                'score' => $hit['vector_distance'] ?? null,
            ],
            $hits,
        );
    }

    /**
     * @param array<int, float> $embedding
     * @param array<string, mixed> $metadata
     */
    public function store(
        string $id,
        string $content,
        array $embedding,
        string $sourceType,
        string $sourceName,
        array $metadata = [],
    ): void {
        $expectedDimension = $this->dimension();

        if (count($embedding) !== $expectedDimension) {
            throw new InvalidArgumentException(
                'Embedding has ' . count($embedding) . " dimensions, expected {$expectedDimension} "
                . "(set via ConfigurationEnum::TYPESENSE_VECTOR_DIMENSION) for this app's knowledge collection.",
            );
        }

        $client = $this->client();
        $collection = $this->collection();

        $this->ensureSchema($client, $collection, $expectedDimension);

        $client->collections[$collection]->documents->upsert([
            'id' => $id,
            'content' => $content,
            'embedding' => $embedding,
            'sourceType' => $sourceType,
            'sourceName' => $sourceName,
            ...$metadata,
        ]);
    }

    /**
     * Removes every chunk previously stored under this source, so re-ingesting
     * an updated document replaces the old passages instead of piling up
     * duplicates alongside them.
     */
    public function deleteBySource(string $sourceType, string $sourceName): void
    {
        $client = $this->client();
        $collection = $this->collection();

        if (! $this->collectionExists($client, $collection)) {
            return;
        }

        $client->collections[$collection]->documents->delete([
            'filter_by' => "sourceType:={$sourceType} && sourceName:={$sourceName}",
        ]);
    }

    private function collectionExists(Client $client, string $collection): bool
    {
        try {
            $client->collections[$collection]->retrieve();

            return true;
        } catch (ObjectNotFound) {
            return false;
        }
    }

    private function ensureSchema(Client $client, string $collection, int $dimension): void
    {
        try {
            $client->collections[$collection]->retrieve();
        } catch (ObjectNotFound) {
            $client->collections->create([
                'name' => $collection,
                'fields' => [
                    ['name' => 'content', 'type' => 'string'],
                    ['name' => 'sourceType', 'type' => 'string', 'facet' => true],
                    ['name' => 'sourceName', 'type' => 'string', 'facet' => true],
                    ['name' => 'embedding', 'type' => 'float[]', 'num_dim' => $dimension],
                ],
            ]);
        }
    }
}
