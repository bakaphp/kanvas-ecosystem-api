<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Listeners;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Knowledge\Jobs\IndexLeadKnowledgeJob;
use Kanvas\Intelligence\Knowledge\Services\LeadRagComponents;
use Kanvas\Social\Channels\Events\ChannelMessageAttachedEvent;
use Kanvas\SystemModules\Models\SystemModules;

class QueueLeadKnowledgeIndexListener
{
    public function handle(ChannelMessageAttachedEvent $event): void
    {
        // @todo Replace this Lead namespace check with multi-entity RAG indexer resolution.
        $namespace = SystemModules::convertLegacySystemModules(
            (string) $event->channel->entity_namespace
        );
        if ($namespace !== Lead::class || $event->channel->entity_id === null) {
            return;
        }

        $lead = Lead::query()
            ->where('id', (int) $event->channel->entity_id)
            ->where('apps_id', $event->channel->apps_id)
            ->where('companies_id', $event->channel->companies_id)
            ->where('is_deleted', 0)
            ->first();

        if (
            $lead !== null
            && LeadRagComponents::isEnabled($lead)
        ) {
            IndexLeadKnowledgeJob::dispatch($lead);
        }
    }
}
