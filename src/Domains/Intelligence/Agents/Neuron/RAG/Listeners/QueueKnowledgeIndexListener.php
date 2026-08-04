<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\RAG\Listeners;

use Kanvas\Intelligence\Agents\Neuron\RAG\IndexKnowledgeJob;
use Kanvas\Intelligence\Knowledge\Events\KnowledgeIndexRequested;

final class QueueKnowledgeIndexListener
{
    public function handle(KnowledgeIndexRequested $event): void
    {
        IndexKnowledgeJob::dispatch($event->entity)
            ->delay(now()->addSeconds(5))
            ->afterCommit();
    }
}
