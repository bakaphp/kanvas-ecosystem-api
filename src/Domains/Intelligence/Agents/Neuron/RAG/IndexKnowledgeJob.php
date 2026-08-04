<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\RAG;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeEntity;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeSourceRegistry;

class IndexKnowledgeJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;
    public int $timeout = 120;
    public int $uniqueFor = 60;

    public function __construct(public readonly KnowledgeEntity $entity)
    {
    }

    public function handle(KnowledgeSourceRegistry $sources): void
    {
        $entity = $sources->resolve(
            $this->entity->type,
            $this->entity->id,
            $this->entity->appId,
            $this->entity->companyId,
        );

        if ($entity !== null && RagComponents::isEnabled($entity)) {
            new KnowledgeIndexer($sources)->index($entity);
        }
    }

    public function uniqueId(): string
    {
        return $this->entity->key();
    }
}
