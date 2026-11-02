<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Services;

use Kanvas\Connectors\Slack\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Users\Models\Users;

/**
 * The Kanvas channel that mirrors one Slack conversation. Keyed on team + Slack channel id, so the
 * ask an agent sends and the reply that comes back land in the same channel whichever side opened it.
 */
class SlackConversationChannelService
{
    public function channelFor(
        Agent $agent,
        Users $owner,
        string $teamId,
        string $slackChannelId,
    ): Channel {
        $channel = new CreateChannelAction(
            new ChannelDto(
                apps: $agent->app,
                companies: $agent->company,
                users: $owner,
                entity_id: $owner->getId(),
                entity_namespace: Users::class,
                name: 'Slack ' . $slackChannelId,
                description: 'Slack conversation with ' . $agent->name,
                slug: SessionChannelService::createChannelSlug('slack', $teamId . '-' . $slackChannelId),
            ),
        )->execute();

        $channel->set(IntelligenceConfigurationEnum::AGENT_CHANNEL_TYPE->value, 'SLACK');
        $channel->set(ConfigurationEnum::AGENT_ID->value, $agent->getId());

        return $channel;
    }
}
