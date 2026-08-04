<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\RAG\Listeners;

use Kanvas\Intelligence\Agents\Neuron\RAG\RagComponents;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeEntity;
use Kanvas\Intelligence\Knowledge\Events\KnowledgeIndexRequested;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeSourceRegistry;
use Kanvas\Social\Channels\Events\ChannelMessageAttachedEvent;
use Kanvas\SystemModules\Models\SystemModules;

final class QueueChannelKnowledgeIndexListener
{
    public function __construct(
        private readonly KnowledgeSourceRegistry $sources = new KnowledgeSourceRegistry(),
    ) {
    }

    public function handle(ChannelMessageAttachedEvent $event): void
    {
        if ($event->channel->entity_id === null) {
            return;
        }

        $entity = $this->sources->resolve(
            SystemModules::convertLegacySystemModules((string) $event->channel->entity_namespace),
            (int) $event->channel->entity_id,
            (int) $event->channel->apps_id,
            (int) $event->channel->companies_id,
        );

        if ($entity !== null && RagComponents::isEnabled($entity)) {
            KnowledgeIndexRequested::dispatch(KnowledgeEntity::fromModel($entity));
        }
    }
}
