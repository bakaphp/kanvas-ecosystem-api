<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\RAG\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Kanvas\Intelligence\Agents\Neuron\RAG\Services\RagComponents;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeEntity;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeSourceRegistry;

class IndexKnowledgeJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use KanvasJobsTrait;

    public int $tries = 3;
    public int $timeout = 120;
    public int $uniqueFor = 60;

    public function __construct(
        public readonly KnowledgeEntity $entity
    ) {
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
