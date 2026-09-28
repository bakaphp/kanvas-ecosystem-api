<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Slack\Services\SlackListenerReceiverService;
use Kanvas\Users\Models\Users;

/**
 * The customer creates the app in their own workspace — see GenerateSlackManifestAction for why we
 * never call apps.manifest.create.
 *
 * The scope list is the entire security story of this feature: no chat:write and no im:*, so the
 * token this manifest produces cannot post anything, and cannot see anyone's DMs with the listener.
 */
class GenerateSlackListenerManifestAction
{
    private const int MAX_APP_NAME = 35;

    private const array BOT_SCOPES = [
        'channels:history',
        'channels:join',
        'channels:read',
        'groups:history',
        'groups:read',
        'mpim:history',
        'mpim:read',
        'users:read',
        'users:read.email',
        'files:read',
    ];

    private const array BOT_EVENTS = [
        'message.channels',
        'message.groups',
        'message.mpim',
        'channel_created',
        'app_uninstalled',
        'tokens_revoked',
    ];

    public function __construct(
        private readonly AppInterface $app,
        private readonly Companies $company,
        private readonly Users $user,
    ) {
    }

    /**
     * @return array{manifest_json: string, install_url: string, request_url: string}
     */
    public function execute(): array
    {
        $receiver = new SlackListenerReceiverService()->forCompany(
            $this->app,
            $this->company,
            $this->user,
        );

        $requestUrl = $receiver->getUrl();
        $manifest = (string) json_encode($this->manifest($requestUrl), JSON_UNESCAPED_SLASHES);

        return [
            'manifest_json' => $manifest,
            'install_url' => 'https://api.slack.com/apps?new_app=1&manifest_json=' . urlencode($manifest),
            'request_url' => $requestUrl,
        ];
    }

    private function manifest(string $requestUrl): array
    {
        $name = mb_substr(trim($this->company->name) . ' Pulse', 0, self::MAX_APP_NAME);

        return [
            'display_information' => [
                'name' => $name,
                'description' => 'Records workspace conversation into Kanvas Nervous System.',
            ],
            'features' => [
                'bot_user' => [
                    'display_name' => $name,
                    'always_online' => false,
                ],
                // Read-only, so nobody is left waiting on a reply that is never coming.
                'app_home' => [
                    'messages_tab_enabled' => true,
                    'messages_tab_read_only_enabled' => true,
                ],
            ],
            'oauth_config' => [
                'scopes' => ['bot' => self::BOT_SCOPES],
            ],
            'settings' => [
                'event_subscriptions' => [
                    'request_url' => $requestUrl,
                    'bot_events' => self::BOT_EVENTS,
                ],
                'org_deploy_enabled' => false,
                'socket_mode_enabled' => false,
                'token_rotation_enabled' => false,
            ],
        ];
    }
}
