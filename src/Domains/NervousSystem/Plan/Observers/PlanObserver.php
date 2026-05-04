<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Observers;

use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelData;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;
use Throwable;

class PlanObserver
{
    public function updating(Plan $plan): void
    {
        $plan->clearLightHouseCache(withKanvasConfiguration: false);
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
