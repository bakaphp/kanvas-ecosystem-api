<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Actions;

use Kanvas\Connectors\Slack\Services\SlackReceiverService;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * Builds the Slack app manifest for an agent, plus the link the customer clicks to create it.
 *
 * We do NOT call apps.manifest.create. That would put the app in Kanvas's Slack org, and installing
 * it into the customer's workspace would then require Public Distribution — a toggle with no API,
 * flipped by a human on api.slack.com, once per app. 200 agents would be 200 manual toggles.
 *
 * So the customer creates the app in THEIR OWN workspace from this manifest. App and workspace are
 * then the same org, distribution never enters the picture, and they only have to paste the tokens
 * back. Requires a Slack admin (or app-install permission) on their side — that's the accepted cost.
 */
class GenerateSlackManifestAction
{
    private const int MAX_APP_NAME = 35;
    private const int MAX_BOT_NAME = 80;

    private const array BOT_SCOPES = [
        'chat:write',
        'app_mentions:read',
        'im:history',
        'im:read',
        'im:write',
        'mpim:history',
        'mpim:read',
        'channels:history',
        'channels:read',
        // Lets the bot add itself to public channels, which is what "listen to everything" needs:
        // Slack delivers message.channels only for channels the bot is a member of. Private channels
        // have no equivalent — a human has to /invite the bot.
        'channels:join',
        'groups:history',
        'groups:read',
        'files:read',
        'remote_files:read',
        'files:write',
        'reactions:read',
        'reactions:write',
        'users:read',
        'users:read.email',
        'users.profile:read',
    ];

    /**
     * The room-message events are subscribed unconditionally even though listen-all defaults to off.
     * A manifest is consumed once, when the customer creates their Slack app; adding an event later
     * means walking every customer back through Slack's UI. Subscribing now and gating in code makes
     * the toggle a pure Kanvas-side switch — the cost is inbound events we drop early when it's off.
     */
    private const array BOT_EVENTS = [
        'app_mention',
        'message.im',
        'message.channels',
        'message.groups',
        'message.mpim',
        'channel_created',
        'app_uninstalled',
        'tokens_revoked',
    ];

    public function __construct(
        private readonly Agent $agent,
    ) {
    }

    /**
     * @return array{manifest_json: string, install_url: string, request_url: string}
     */
    public function execute(): array
    {
        $receiver = new SlackReceiverService()->forAgent($this->agent);

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
        // Slack rejects a manifest with an empty app name or bot display_name, and an agent's name
        // is nullable in practice — fall back to something stable and unique so provisioning never
        // dead-ends.
        $name = trim($this->agent->name ?? '') ?: 'Kanvas Agent ' . (int) $this->agent->getId();

        return [
            'display_information' => [
                'name' => mb_substr($name, 0, self::MAX_APP_NAME),
                'description' => mb_substr('Kanvas agent: ' . $name, 0, self::MAX_APP_NAME * 2),
            ],
            'features' => [
                'bot_user' => [
                    'display_name' => mb_substr($name, 0, self::MAX_BOT_NAME),
                    'always_online' => true,
                ],
                // The Messages tab IS the DM surface. Off, clicking the agent gives no place to
                // type — and DM is half of how you talk to the agent.
                'app_home' => [
                    'messages_tab_enabled' => true,
                    'messages_tab_read_only_enabled' => false,
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
                // Socket Mode is the container runtimes' path (Hermes/OpenClaw open their own
                // socket). This app talks to us over the Events API.
                'socket_mode_enabled' => false,
                'token_rotation_enabled' => false,
            ],
        ];
    }
}
