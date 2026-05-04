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
        } catch (Throwable $e) {
           report($e);
        }
    }

    public function deleted(AgentSwarm $swarm): void
    {
        foreach ($swarm->socialChannels as $channel) {
            $channel->delete();
        }
    }
}
