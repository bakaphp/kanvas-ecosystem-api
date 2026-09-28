<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Embedders;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Knowledge\Contracts\KnowledgeEmbedder;
use Kanvas\Intelligence\Knowledge\Enums\KnowledgeConfigurationEnum;
use Laravel\Ai\Embeddings;
use Override;

/**
 * The single embedding path both stacks share, built on the laravel/ai SDK so
 * Neuron (via NeuronEmbeddingsAdapter) and the Laravel tool use identical
 * vectors within a collection. Defaults to Gemini/gemini-embedding-2/768 to
 * match the existing Neuron Lead RAG indexes; an app may override provider,
 * model, or dimension via KnowledgeConfigurationEnum.
 *
 * Per-app keys: laravel/ai's AiManager caches resolved providers by name on its
 * singleton, so a shared provider name would leak one tenant's key into
 * another's request. We register a provider named per (app, provider) at call
 * time — this is the ONE sanctioned place that mutates config('ai.providers.*')
 * (Octane-fragile by nature; keep it here so it is testable and singular).
 */
final class LaravelAiKnowledgeEmbedder implements KnowledgeEmbedder
{
    /** Gemini rejects BatchEmbedContentsRequest payloads larger than 100 items. */
    private const MAX_BATCH_SIZE = 90;

    private const DEFAULT_PROVIDER = 'gemini';

    private const DEFAULT_MODELS = [
        'gemini' => 'gemini-embedding-2',
        'openai' => 'text-embedding-3-small',
    ];

    private const PROVIDER_KEYS = [
        'gemini' => ConfigurationEnum::GEMINI_KEY,
        'openai' => ConfigurationEnum::OPEN_AI_EMBEDDINGS_KEY,
    ];

    public function __construct(private readonly Apps $app)
    {
    }

    #[Override]
    public function embed(string $text): array
    {
        return $this->embedBatch([$text])[0] ?? [];
    }

    #[Override]
    public function embedBatch(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $embeddings = [];
        foreach (array_chunk(array_values($texts), self::MAX_BATCH_SIZE) as $batch) {
            $embeddings = array_merge(
                $embeddings,
                Embeddings::for($batch)
                    ->dimensions($this->dimension())
                    ->generate(provider: $this->providerName(), model: $this->model())
                    ->embeddings,
            );
        }

        return array_map(static fn (array $vector): array => array_values($vector), $embeddings);
    }

    #[Override]
    public function dimension(): int
    {
        return max((int) ($this->app->get(KnowledgeConfigurationEnum::VECTOR_DIMENSION->value) ?? 768), 1);
    }

    #[Override]
    public function identifier(): string
    {
        return implode(':', [$this->provider(), $this->model(), $this->dimension()]);
    }

    private function provider(): string
    {
        $provider = strtolower(trim((string) $this->app->get(KnowledgeConfigurationEnum::EMBEDDING_PROVIDER->value)));

        return isset(self::DEFAULT_MODELS[$provider]) ? $provider : self::DEFAULT_PROVIDER;
    }

    private function model(): string
    {
        $model = trim((string) $this->app->get(KnowledgeConfigurationEnum::EMBEDDING_MODEL->value));

        return $model !== '' ? $model : self::DEFAULT_MODELS[$this->provider()];
    }

    private function providerName(): string
    {
        $provider = $this->provider();
        $name = "knowledge_{$provider}_app_{$this->app->getId()}";
        $keyEnum = self::PROVIDER_KEYS[$provider];

        config(["ai.providers.{$name}" => [
            'driver' => $provider,
            'key' => $this->app->get($keyEnum->value) ?? config("ai.providers.{$provider}.key"),
        ]]);

        return $name;
    }
}
