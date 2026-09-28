<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Slack\Client;
use Kanvas\Connectors\Slack\Enums\ConfigurationEnum;
use Kanvas\Connectors\Slack\Jobs\JoinAllPublicChannelsJob;
use Kanvas\Connectors\Slack\Services\SlackListenerReceiverService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Users\Models\Users;

/**
 * auth.test doubles as the only real validation — a bad token is rejected here rather than
 * surfacing weeks later as a workspace nobody was actually recording.
 */
class ConnectSlackListenerAction
{
    /**
     * @param list<string> $channelDenyList
     */
    public function __construct(
        private readonly AppInterface $app,
        private readonly Companies $company,
        private readonly Users $user,
        private readonly string $botToken,
        private readonly string $signingSecret,
        private readonly array $channelDenyList = [],
        private readonly bool $ingestFiles = false,
    ) {
    }

    /**
     * @return array{connected: bool, team_id: string, team_name: string, bot_user_id: string, request_url: string, channels_joined: int}
     */
    public function execute(): array
    {
        if (! str_starts_with($this->botToken, 'xoxb-')) {
            throw new ValidationException('Expected a bot token (xoxb-…). A user token will not work.');
        }

        $identity = new Client($this->botToken)->authTest();

        $receiver = new SlackListenerReceiverService()->forCompany(
            $this->app,
            $this->company,
            $this->user,
        );

        $receiver->configuration = [
            ...$receiver->configuration,
            ConfigurationEnum::BOT_TOKEN->value => $this->botToken,
            ConfigurationEnum::SIGNING_SECRET->value => $this->signingSecret,
            ConfigurationEnum::BOT_USER_ID->value => (string) ($identity['user_id'] ?? ''),
            ConfigurationEnum::TEAM_ID->value => (string) ($identity['team_id'] ?? ''),
            ConfigurationEnum::TEAM_NAME->value => (string) ($identity['team'] ?? ''),
            ConfigurationEnum::CHANNEL_DENY_LIST->value => $this->channelDenyList,
            ConfigurationEnum::INGEST_FILES->value => $this->ingestFiles,
        ];
        $receiver->is_active = true;
        $receiver->saveOrFail();

        JoinAllPublicChannelsJob::dispatch($receiver->app, $receiver);

        return [
            'connected' => true,
            'team_id' => (string) ($identity['team_id'] ?? ''),
            'team_name' => (string) ($identity['team'] ?? ''),
            'bot_user_id' => (string) ($identity['user_id'] ?? ''),
            'request_url' => $receiver->getUrl(),
            // The sweep has only just been queued; on a reconnect this is the previous run's tally.
            'channels_joined' => (int) ($receiver->configuration[ConfigurationEnum::CHANNELS_JOINED->value] ?? 0),
        ];
    }
}
