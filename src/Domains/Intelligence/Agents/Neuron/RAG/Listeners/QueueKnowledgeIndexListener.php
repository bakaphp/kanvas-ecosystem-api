<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\RAG\Listeners;

use Kanvas\Intelligence\Agents\Neuron\RAG\Jobs\IndexKnowledgeJob;
use Kanvas\Intelligence\Knowledge\Events\KnowledgeIndexRequested;

final class QueueKnowledgeIndexListener
{
    public function handle(KnowledgeIndexRequested $event): void
    {
        // Wide enough that a burst of channel messages on one lead collapses into a single
        // re-embed through the job's unique lock, instead of one embedding call per message.
        IndexKnowledgeJob::dispatch($event->entity)
            ->delay(now()->addSeconds(60))
            ->afterCommit();
    }
}
