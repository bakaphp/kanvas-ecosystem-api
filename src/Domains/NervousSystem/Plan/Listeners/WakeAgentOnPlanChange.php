<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Listeners;

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
        if ($event->plan->agent_id === null) {
            return false;
        }

        if (! in_array($event->changeType, [PlanChangeTypeEnum::CREATED, PlanChangeTypeEnum::APPROVED], true)) {
            return false;
        }

        return (bool) ($event->plan->agent?->is_active ?? false);
    }
}
