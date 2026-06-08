<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Listeners;

use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\NervousSystem\Plan\Enums\PlanChangeTypeEnum;
use Kanvas\NervousSystem\Plan\Events\PlanBroadcast;
use Kanvas\NervousSystem\Plan\Jobs\WakeAgentForPlanJob;

class WakeAgentOnPlanChange
{
    public function handle(PlanBroadcast $event): void
    {
        if (! $this->shouldWake($event)) {
            return;
        }

        $reason = $event->changeType === PlanChangeTypeEnum::APPROVED
            ? WakeAgentForPlanJob::REASON_APPROVED
            : WakeAgentForPlanJob::REASON_PLAN_ASSIGNED;

        $event->plan->emitLedgerEvent(
            'plan.agent.wake_dispatched',
            payload: [
                'agent_id' => $event->plan->agent_id,
                'reason' => $reason,
                'source' => 'listener',
                'change_type' => $event->changeType->value,
            ],
        );

        WakeAgentForPlanJob::dispatch(
            $event->plan,
            $reason,
        );
    }

    protected function shouldWake(PlanBroadcast $event): bool
    {
        // Sync-originated changes already reflect what the agent did on its own board —
        // waking it through the chat path would bounce its own work back at it (loop).
        if ($event->fromSync) {
            return false;
        }

        if ($event->plan->agent_id === null) {
            return false;
        }

        if (! in_array($event->changeType, [PlanChangeTypeEnum::CREATED, PlanChangeTypeEnum::APPROVED], true)) {
            return false;
        }

        // Kanban-driven runtime agents (Hermes) get a board card via PushPlanChangeToKanban and work
        // it there — the chat wake would make them do it twice. In-process agents keep the chat wake.
        $deployment = $event->plan->agent?->activeDeployment;
        if ($deployment instanceof AgentDeployment
            && $deployment->isRunning()
            && AgentProviderEnum::forDeployment($deployment)->isHermes()
        ) {
            return false;
        }

        return (bool) ($event->plan->agent?->is_active ?? false);
    }
}
