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
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeEntity;
use Kanvas\Intelligence\Knowledge\Exceptions\CollectionUpdateInProgressException;
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

    /** A schema alter on a large collection takes minutes; a released job must not land inside it again. */
    private const int SCHEMA_UPDATE_RETRY_SECONDS = 90;

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
        $source = $sources->for($this->entity->type);
        $entity = $source?->find($this->entity->id, $this->entity->appId, $this->entity->companyId);

        if ($source === null || $entity === null || ! $source->isEnabledFor($entity->app)) {
            return;
        }

        $this->overwriteAppService($entity->app);

        try {
            KnowledgeComponents::indexer($entity->app)->indexEntity($source, $entity);
        } catch (CollectionUpdateInProgressException) {
            $this->release(self::SCHEMA_UPDATE_RETRY_SECONDS);
        }
    }

    public function uniqueId(): string
    {
        return $this->entity->key();
    }
}
