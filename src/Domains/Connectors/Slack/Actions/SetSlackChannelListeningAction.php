<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Actions;

use Kanvas\Connectors\Slack\Enums\ConfigurationEnum;
use Kanvas\Connectors\Slack\Services\SlackReceiverService;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * Turns the agent's ambient-listening mode on or off.
 *
 * Listening is deliberately not answering. With this on, every human message in every channel the
 * bot is in gets ingested into the agent's Kanvas history — but the agent still only takes a turn
 * when it is DM'd or @mentioned. Running an LLM turn per message in a busy workspace would be both a
 * cost blowout and unbearable to work next to.
 */
class SetSlackChannelListeningAction
{
    public function __construct(
        private readonly Agent $agent,
        private readonly bool $enabled,
        private readonly bool $joinExistingChannels = true,
    ) {
    }

    /**
     * @return array{listening_all_channels: bool, joined_channels: list<string>, already_member_channels: list<string>, failed_channels: list<string>}
     */
    public function execute(): array
    {
        $receiver = new SlackReceiverService()->forAgent($this->agent);

        $receiver->configuration = [
            ...$receiver->configuration,
            ConfigurationEnum::LISTEN_ALL_CHANNELS->value => $this->enabled,
        ];
        $receiver->saveOrFail();

        $membership = ['joined' => [], 'already_member' => [], 'failed' => []];

        if ($this->enabled && $this->joinExistingChannels) {
            $membership = new JoinAllSlackChannelsAction($this->agent)->execute();
        }

        return [
            'listening_all_channels' => $this->enabled,
            'joined_channels' => $membership['joined'],
            'already_member_channels' => $membership['already_member'],
            'failed_channels' => $membership['failed'],
        ];
    }
}
