<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Listeners;

use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\NervousSystem\Plan\Enums\PlanChangeTypeEnum;
use Kanvas\NervousSystem\Plan\Events\PlanBroadcast;
use Kanvas\NervousSystem\Plan\Jobs\PushPlanToKanbanJob;
use Kanvas\NervousSystem\Plan\Jobs\PushTaskToKanbanJob;

/**
 * Mirrors a Kanvas-originated plan/task change down to the agent's Hermes board. Skips sync-origin
 * changes (the echo guard) and only fires for agents with a running Hermes deployment — `running`
 * tasks the agent drives itself are observed via ingest, never pushed.
 */
class PushPlanChangeToKanban
{
    public function handle(PlanBroadcast $event): void
    {
        if ($event->fromSync) {
            return;
        }

        $agent = $event->plan->agent;

        if ($agent === null) {
            return;
        }

        $deployment = $agent->activeDeployment;

        if (! $deployment instanceof AgentDeployment
            || ! $deployment->isRunning()
            || ! AgentProviderEnum::forDeployment($deployment)->isHermes()
        ) {
            return;
        }

        if (in_array($event->changeType, [PlanChangeTypeEnum::CREATED, PlanChangeTypeEnum::UPDATED], true)) {
            PushPlanToKanbanJob::dispatch($event->plan);

            return;
        }

        if ($event->task !== null
            && in_array(
                $event->changeType,
                [PlanChangeTypeEnum::TASK_ADDED, PlanChangeTypeEnum::TASK_STATUS_CHANGED],
                true,
            )
        ) {
            PushTaskToKanbanJob::dispatch($event->task);
        }
    }
}
