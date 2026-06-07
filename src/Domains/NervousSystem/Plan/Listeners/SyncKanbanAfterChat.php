<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Listeners;

use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Events\AgentChatResponseEvent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\NervousSystem\Plan\Jobs\SyncDeploymentKanbanJob;

/**
 * Snappy ingest: right after a chat turn with a running Hermes agent, kick a one-off board sync so
 * tasks the agent just created via its kanban tools surface in Kanvas in seconds instead of waiting
 * for the 5-min cron. The sync is watermark-free (status-diff), so an extra run is harmless.
 */
class SyncKanbanAfterChat
{
    public function handle(AgentChatResponseEvent $event): void
    {
        $deployment = $event->agent()->activeDeployment;

        if (! $deployment instanceof AgentDeployment
            || ! $deployment->isRunning()
            || ! AgentProviderEnum::forDeployment($deployment)->isHermes()
        ) {
            return;
        }

        SyncDeploymentKanbanJob::dispatch($deployment);
    }
}
