<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Services;

use Kanvas\Connectors\Slack\Enums\ConfigurationEnum;
use Kanvas\Connectors\Slack\Enums\CustomFieldEnum;
use Kanvas\Intelligence\AgentRuntime\Enums\AgentChannelTokenEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Models\ReceiverWebhook;

class SlackConnectionStatusService
{
    /**
     * @return array{connected: bool, team_id: string, team_name: string, bot_user_id: string, request_url: string}|null
     */
    public function forAgent(Agent $agent): ?array
    {
        $botToken = (string) ($agent->get(AgentChannelTokenEnum::SLACK_BOT_TOKEN->value) ?? '');
        $receiverId = $agent->get(CustomFieldEnum::RECEIVER_ID->value);

        if ($botToken === '' || $receiverId === null) {
            return null;
        }

        $receiver = ReceiverWebhook::query()
            ->where('id', (int) $receiverId)
            ->fromApp($agent->app)
            ->notDeleted()
            ->first();

        if ($receiver === null || ! $receiver->is_active) {
            return null;
        }

        /** @var array<string, mixed> $configuration */
        $configuration = $receiver->configuration ?? [];

        return [
            'connected' => true,
            'team_id' => (string) ($configuration[ConfigurationEnum::TEAM_ID->value] ?? ''),
            'team_name' => (string) ($configuration[ConfigurationEnum::TEAM_NAME->value] ?? ''),
            'bot_user_id' => (string) ($configuration[ConfigurationEnum::BOT_USER_ID->value] ?? ''),
            'request_url' => (string) $receiver->getUrl(),
        ];
    }
}
