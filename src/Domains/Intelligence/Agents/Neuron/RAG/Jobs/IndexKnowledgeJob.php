<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\RAG\Jobs;

use Baka\Traits\KanvasJobsTrait;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Kanvas\Intelligence\Agents\Neuron\RAG\Services\RagComponents;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeEntity;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeSourceRegistry;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

class IndexKnowledgeJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use KanvasJobsTrait;

    public int $maxExceptions = 3;
    public int $timeout = 120;
    public int $uniqueFor = 60;

    public function __construct(
        public readonly KnowledgeEntity $entity
    ) {
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(2);
    }

    /**
     * Keyed by app because the embedding key is per app: once one app's key is rate
     * limited, every queued index job for that app waits instead of hammering Gemini.
     */
    public function middleware(): array
    {
        return [
            new ThrottlesExceptions(maxAttempts: 5, decaySeconds: 120)
                ->by('knowledge-embeddings:app:' . $this->entity->appId)
                ->when(fn (Throwable $e) => $e instanceof RateLimitedException)
                ->backoff(2),
        ];
    }

    public function handle(KnowledgeSourceRegistry $sources): void
    {
        $entity = $sources->resolve(
            $this->entity->type,
            $this->entity->id,
            $this->entity->appId,
            $this->entity->companyId,
        );

        if ($entity === null || ! RagComponents::isEnabled($entity)) {
            return;
        }

        $this->overwriteAppService($entity->app);

        $source = $sources->for($entity::class);

        if ($source !== null) {
            KnowledgeComponents::indexer($entity->app)->indexEntity($source, $entity);
        }
    }

    public function uniqueId(): string
    {
        return $this->entity->key();
    }
}
