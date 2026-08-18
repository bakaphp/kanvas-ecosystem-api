<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Observers;

use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Jobs\NotifyPlanOwnerOfBlockedPlanJob;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Project\Jobs\NotifyProjectOwnerOfBlockedPlanJob;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelData;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;
use Throwable;

class PlanObserver
{
    public function updating(Plan $plan): void
    {
        $plan->clearLightHouseCache(withKanvasConfiguration: false);

        // Record the assignee that just blocked this plan so the PM can't re-hand it to the same agent
        // (which would only re-block). Folded into this same UPDATE — no extra write. See NS-6909.
        if ($plan->isDirty('status')
            && $plan->status === PlanStatusEnum::BLOCKED->value
            && $plan->agent_id !== null
        ) {
            $declined = $plan->capability_declined_agent_ids ?? [];

            if (! in_array($plan->agent_id, $declined, true)) {
                $declined[] = $plan->agent_id;
                $plan->capability_declined_agent_ids = $declined;
            }
        }
    }

    public function updated(Plan $plan): void
    {
        if (! $plan->wasChanged('status') || $plan->status !== PlanStatusEnum::BLOCKED->value) {
            return;
        }

        // No project means no PM watching — and a person is waiting on it.
        if ($plan->project_id === null) {
            NotifyPlanOwnerOfBlockedPlanJob::dispatch($plan)->delay(now()->addSeconds(45));

            return;
        }

        // Delay so a burst of near-simultaneous blocks settles — the first job to run then digests
        // ALL of them into ONE alert, and the rest are suppressed by the per-project throttle.
        NotifyProjectOwnerOfBlockedPlanJob::dispatch($plan)->delay(now()->addSeconds(45));
    }

    public function created(Plan $plan): void
    {
        $owner = $plan->user;

        if ($owner === null) {
            return;
        }

        try {
            new CreateChannelAction(
                new ChannelData(
                    apps: $plan->app,
                    companies: $plan->company,
                    users: $owner,
                    entity_id: (string) $plan->getKey(),
                    entity_namespace: Plan::class,
                    name: ChannelNameEnum::ACTIVITIES->value,
                    description: $plan->description ?? $plan->title,
                    slug: $plan->uuid,
                ),
            )->execute();
        } catch (Throwable) {
            // Channel creation must not block plan creation. Failures are
            // tolerated — a plan without a channel still works; the FE can
            // create one on first message if missing.
        }
    }
}
