<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Observers;

use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Jobs\NotifyPlanOwnerOfBlockedPlanJob;
use Kanvas\NervousSystem\Plan\Jobs\NotifyPlanOwnerOfCompletedPlanJob;
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
        if (! $plan->wasChanged('status')) {
            return;
        }

        // Success needs telling as much as failure does. Unlike a block it goes to the plan's OWNER
        // even when a project exists: the PM is who finished the work, so routing "it's done" to the
        // PM tells the one person who already knows.
        if ($plan->status === PlanStatusEnum::DONE->value) {
            NotifyPlanOwnerOfCompletedPlanJob::dispatch($plan)->delay(now()->addSeconds(45));

            return;
        }

        if ($plan->status !== PlanStatusEnum::BLOCKED->value) {
            return;
        }

        // No project means no PM watching — and a person is waiting on it. A plan under a project takes
        // the same route only when a PERSON is the one who can unblock it, so they are interrupted for
        // what they can act on; a capability block belongs to the operator digest below.
        if ($plan->project_id === null || $plan->blockedNeedsAHuman()) {
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
