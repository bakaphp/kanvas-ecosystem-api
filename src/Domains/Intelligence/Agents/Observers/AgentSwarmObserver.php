<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Observers;

use Kanvas\Intelligence\Agents\Models\AgentSwarm;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelData;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;
use Throwable;

class AgentSwarmObserver
{
    /**
     * The Activities channel scoped to the swarm itself is the org-level
     * feed — humans see all messages across the swarm in one stream, and
     * agents query it for context that crosses individual plans.
     */
    public function created(AgentSwarm $swarm): void
    {
        $owner = $swarm->user;

        if ($owner === null) {
            return;
        }

        try {
            new CreateChannelAction(
                new ChannelData(
                    apps: $swarm->app,
                    companies: $swarm->company,
                    users: $owner,
                    entity_id: (string) $swarm->getKey(),
                    entity_namespace: AgentSwarm::class,
                    name: ChannelNameEnum::ACTIVITIES->value,
                    description: $swarm->description ?? $swarm->name,
                    slug: $swarm->uuid,
                ),
            )->execute();
        } catch (Throwable) {
            // Channel creation must not block swarm creation. A swarm
            // without a channel still works; the FE can create one later.
        }
    }

    public function deleted(AgentSwarm $swarm): void
    {
        foreach ($swarm->socialChannels as $channel) {
            $channel->delete();
        }
    }
}
