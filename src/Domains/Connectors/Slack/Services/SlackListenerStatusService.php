<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Slack\Enums\ConfigurationEnum;

class SlackListenerStatusService
{
    /**
     * @return array{connected: bool, team_id: string, team_name: string, bot_user_id: string, request_url: string, channels_joined: int}|null
     *         null when the company has never connected a listener
     */
    public function forCompany(AppInterface $app, CompanyInterface $company): ?array
    {
        $receiver = new SlackListenerReceiverService()->findForCompany($app, $company);

        if ($receiver === null || ! $receiver->is_active) {
            return null;
        }

        $configuration = $receiver->configuration;

        if ((string) ($configuration[ConfigurationEnum::BOT_TOKEN->value] ?? '') === '') {
            return null;
        }

        return [
            'connected' => true,
            'team_id' => (string) ($configuration[ConfigurationEnum::TEAM_ID->value] ?? ''),
            'team_name' => (string) ($configuration[ConfigurationEnum::TEAM_NAME->value] ?? ''),
            'bot_user_id' => (string) ($configuration[ConfigurationEnum::BOT_USER_ID->value] ?? ''),
            'request_url' => $receiver->getUrl(),
            'channels_joined' => (int) ($configuration[ConfigurationEnum::CHANNELS_JOINED->value] ?? 0),
        ];
    }
}
