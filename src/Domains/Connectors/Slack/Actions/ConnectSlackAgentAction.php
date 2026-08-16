<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Actions;

use Kanvas\Connectors\Slack\Client;
use Kanvas\Connectors\Slack\Enums\ConfigurationEnum;
use Kanvas\Connectors\Slack\Services\SlackReceiverService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Enums\AgentChannelTokenEnum;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * Binds a customer-created Slack app to one agent.
 *
 * The customer pastes two things (bot token + signing secret); everything else — bot user id, team,
 * app id — we read back from Slack with auth.test. That call doubles as the only real validation:
 * a token that can't authenticate is rejected here instead of surfacing as a silent no-reply later.
 *
 * The bot token goes on the SHARED AgentChannelTokenEnum key, not a Slack-connector-private one, so
 * a container runtime (Hermes/OpenClaw, which opens its own Slack socket) reads the same credential.
 * The signing secret lives on the receiver: authenticateRequest() is static and only has the
 * receiver in hand when a request arrives.
 */
class ConnectSlackAgentAction
{
    public function __construct(
        private readonly Agent $agent,
        private readonly string $botToken,
        private readonly string $signingSecret,
    ) {
    }

    /**
     * @return array{connected: bool, team_id: string, team_name: string, bot_user_id: string, request_url: string, listening_all_channels: bool}
     */
    public function execute(): array
    {
        if (! str_starts_with($this->botToken, 'xoxb-')) {
            // A user token (xoxp-) authenticates fine and then can't post as the bot — the failure
            // would only show up as an agent that never answers.
            throw new ValidationException('Expected a bot token (xoxb-…). A user token will not work.');
        }

        $identity = new Client($this->botToken)->authTest();

        $receiver = new SlackReceiverService()->forAgent($this->agent);

        $this->agent->set(AgentChannelTokenEnum::SLACK_BOT_TOKEN->value, $this->botToken);

        $receiver->configuration = [
            ...$receiver->configuration,
            ConfigurationEnum::AGENT_ID->value => $this->agent->getId(),
            ConfigurationEnum::SIGNING_SECRET->value => $this->signingSecret,
            ConfigurationEnum::BOT_USER_ID->value => (string) ($identity['user_id'] ?? ''),
            ConfigurationEnum::TEAM_ID->value => (string) ($identity['team_id'] ?? ''),
            ConfigurationEnum::TEAM_NAME->value => (string) ($identity['team'] ?? ''),
        ];
        $receiver->is_active = true;
        $receiver->saveOrFail();

        return [
            'connected' => true,
            'team_id' => (string) ($identity['team_id'] ?? ''),
            'team_name' => (string) ($identity['team'] ?? ''),
            'bot_user_id' => (string) ($identity['user_id'] ?? ''),
            'request_url' => $receiver->getUrl(),
            'listening_all_channels' => (bool) ($receiver->configuration[ConfigurationEnum::LISTEN_ALL_CHANNELS->value] ?? false),
        ];
    }
}
