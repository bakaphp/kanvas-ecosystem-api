<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Listeners;

use Illuminate\Support\Facades\Log;
use Kanvas\NervousSystem\Plan\Enums\PlanChangeTypeEnum;
use Kanvas\NervousSystem\Plan\Enums\PlanConfigurationEnum;
use Kanvas\NervousSystem\Plan\Events\PlanBroadcast;
use Kanvas\NervousSystem\Plan\Jobs\WakeAgentForPlanJob;

class WakeAgentOnPlanChange
{
    public function handle(PlanBroadcast $event): void
    {
        Log::info('WakeAgentOnPlanChange fired', [
        'plan_id' => $event->plan->id,
        'change_type' => $event->changeType->value,
        'has_agent' => $event->plan->agent_id !== null,
        'auto_wake' => $event->plan->app->get('auto_wake_agents'),
    ]);

        if (! $this->shouldWake($event)) {
            return;
        }

        WakeAgentForPlanJob::dispatch(
            $event->plan,
            WakeAgentForPlanJob::REASON_PLAN_ASSIGNED,
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

        return (bool) $event->plan->app->get(PlanConfigurationEnum::AUTO_WAKE_AGENTS->value);
    }
}
