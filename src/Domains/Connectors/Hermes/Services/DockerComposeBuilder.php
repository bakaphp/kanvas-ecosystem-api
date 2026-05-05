<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\Hermes\Enums\ConfigurationEnum;
use Kanvas\Connectors\Hermes\Enums\CustomFieldEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

/**
 * Generates all configuration files needed to run an Hermes agent in Docker.
 *
 * Output files:
 *  - Dockerfile        — base image + sudo setup (from Templates/Dockerfile or app override)
 *  - docker-compose.yml — gateway, socat proxy, and CLI containers (from Templates/docker-compose.yml)
 *  - hermes.json     — agent config: models, channels (Slack), gateway auth, tools, hooks
 *  - auth-profiles.json — LLM provider API keys (Google, Anthropic)
 *
 * App-level settings (ConfigurationEnum): LLM keys, model defaults, Dockerfile template
 * Agent-level settings (CustomFieldEnum): Slack tokens (each agent has its own Slack app)
 *
 * Bump HERMES_VERSION when upgrading the hermes-docker image.
 */
class DockerComposeBuilder
{
    private const string TEMPLATES_DIR = __DIR__ . '/../Templates';
    private const string HERMES_VERSION = '2026.3.12';

    public static function buildDockerfile(AppInterface $app): string
    {
        $template = $app->get(ConfigurationEnum::DOCKERFILE_TEMPLATE->value);

        if (! empty($template)) {
            return (string) $template;
        }

        return rtrim((string) file_get_contents(self::TEMPLATES_DIR . '/Dockerfile'));
    }

    public static function buildEntrypoint(): string
    {
        return rtrim((string) file_get_contents(self::TEMPLATES_DIR . '/entrypoint.sh'));
    }

    public static function buildDockerCompose(
        AgentDeployment $deployment,
        string $gatewayToken,
        AppInterface $app,
        Agent $agent,
    ): string {
        $envVars = self::buildDefaultEnvironment($app);
        $envVars['NODE_ENV'] = $envVars['NODE_ENV'] ?? 'production';
        $envVars['HERMES_SKIP_SERVICE_CHECK'] = $envVars['HERMES_SKIP_SERVICE_CHECK'] ?? 'true';

        $slackBotToken = $agent->get(CustomFieldEnum::SLACK_BOT_TOKEN->value);
        $slackAppToken = $agent->get(CustomFieldEnum::SLACK_APP_TOKEN->value);
        if (! empty($slackBotToken)) {
            $envVars['SLACK_BOT_TOKEN'] = (string) $slackBotToken;
        }
        if (! empty($slackAppToken)) {
            $envVars['SLACK_APP_TOKEN'] = (string) $slackAppToken;
        }

        $envLines = '';
        foreach ($envVars as $key => $value) {
            $envLines .= "      - {$key}={$value}\n";
        }

        $template = (string) file_get_contents(self::TEMPLATES_DIR . '/docker-compose.yml');

        $imageName = self::getSharedImageName($app);

        return str_replace(
            ['{{CONTAINER_NAME}}', '{{HERMES_DIR}}', '{{GATEWAY_PORT}}', '{{PROXY_PORT}}', '{{ENV_LINES}}', '{{IMAGE_NAME}}', '{{IMAGE_DIR}}'],
            [
                $deployment->container_name,
                $deployment->home_directory . '/.hermes',
                (string) $deployment->gateway_port,
                (string) $deployment->proxy_port,
                $envLines,
                $imageName,
                self::getSharedImageDir($app),
            ],
            $template,
        );
    }

    /**
     * @param array<string, mixed> $channelConfig Optional channel config (e.g. slack bot/app tokens)
     */
    public static function buildHermesConfig(
        Agent $agent,
        string $gatewayToken,
        AppInterface $app,
        array $channelConfig = [],
    ): string {
        $slug = $agent->slug;
        $model = $app->get(ConfigurationEnum::DEFAULT_MODEL->value) ?? 'google/gemini-3.1-pro-preview';

        // Prefer GEMINI_API_KEY; fall back to GOOGLE_API_KEY — both are Google AI Studio keys
        $geminiApiKey = (string) ($app->get(ConfigurationEnum::GEMINI_API_KEY->value)
            ?? $app->get(ConfigurationEnum::GOOGLE_API_KEY->value)
            ?? '');

        $authProfiles = [
            'openai-codex:default' => [
                'provider' => 'openai-codex',
                'mode' => 'oauth',
            ],
        ];

        if ($geminiApiKey !== '') {
            $authProfiles['google:default'] = [
                'provider' => 'google',
                'mode'     => 'api_key',
            ];
        }

        $config = [
            'meta' => [
                'lastTouchedVersion' => self::HERMES_VERSION,
                'lastTouchedAt' => now()->toISOString(),
            ],
            'wizard' => [
                'lastRunAt' => now()->toISOString(),
                'lastRunVersion' => self::HERMES_VERSION,
                'lastRunCommand' => 'onboard',
                'lastRunMode' => 'local',
            ],
            'auth' => [
                'profiles' => $authProfiles,
            ],
            'agents' => [
                'defaults' => [
                    'model' => [
                        'primary' => $model,
                        'fallbacks' => [
                            'google/gemini-3.1-flash-lite-preview',
                            'google/gemini-3.1-pro-preview',
                        ],
                    ],
                    'models' => [
                        'google/gemini-2.5-pro'               => (object) [],
                        'google/gemini-3.1-flash-lite-preview' => (object) [],
                        'google/gemini-3.1-pro-preview'        => (object) [],
                    ],
                    'workspace' => '/home/node/.hermes/workspace',
                ],
                'list' => [
                    [
                        'id' => $slug,
                        'name' => $agent->name,
                        'workspace' => '/home/node/.hermes/workspace',
                        'agentDir' => '/home/node/.hermes/agents/' . $slug . '/agent',
                        'model' => $model,
                    ],
                ],
            ],
            'tools' => [
                'profile' => 'full',
                'exec' => [
                    'security' => 'full',
                ],
                'elevated' => [
                    'enabled' => true,
                    'allowFrom' => [
                        'slack' => ['*'],
                        'telegram' => ['*'],
                    ],
                ],
            ],
            'commands' => [
                'native' => 'auto',
                'nativeSkills' => 'auto',
                'restart' => true,
                'ownerDisplay' => 'raw',
            ],
            'session' => [
                'dmScope' => 'per-channel-peer',
            ],
            'hooks' => [
                'internal' => [
                    'enabled' => true,
                    'entries' => [
                        'boot-md' => ['enabled' => true],
                        'session-memory' => ['enabled' => true],
                    ],
                ],
            ],
            'gateway' => [
                'port' => 18789,
                'mode' => 'local',
                'bind' => 'loopback',
                'auth' => [
                    'mode' => 'token',
                    'token' => $gatewayToken,
                ],
                'http' => [
                    'endpoints' => [
                        'responses' => [
                            'enabled' => true,
                        ],
                    ],
                ],
                'tailscale' => [
                    'mode' => 'off',
                    'resetOnExit' => false,
                ],
                'nodes' => [
                    'denyCommands' => [
                        'camera.snap',
                        'camera.clip',
                        'screen.record',
                        'contacts.add',
                        'calendar.add',
                        'reminders.add',
                        'sms.send',
                    ],
                ],
            ],
            'skills' => [
                'entries' => (object) [],
            ],
            'plugins' => [
                'entries' => [],
            ],
        ];

        $pluginEntries = [];

        if (! empty($geminiApiKey)) {
            $pluginEntries['web-search'] = [
                'enabled' => true,
                'config' => [
                    'webSearch' => [
                        'enabled' => true,
                        'provider' => 'gemini',
                        'gemini' => [
                            'apiKey' => $geminiApiKey,
                        ],
                    ],
                ],
            ];
            $config['skills']['entries'] = [
                'nano-banana-pro' => [
                    'apiKey' => $geminiApiKey,
                ],
            ];
        }

        if (! empty($channelConfig)) {
            $config['channels'] = $channelConfig;
            if (isset($channelConfig['slack'])) {
                $pluginEntries['slack'] = ['enabled' => true];
            }
            if (isset($channelConfig['telegram'])) {
                $pluginEntries['telegram'] = ['enabled' => true];
            }
        }

        $config['plugins']['entries'] = ! empty($pluginEntries) ? $pluginEntries : (object) [];

        return (string) json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public static function buildAuthProfiles(AppInterface $app): string
    {
        $profiles = [];
        $lastGood = [];

        // Accept either GOOGLE_API_KEY or GEMINI_API_KEY — both are Google AI Studio keys
        $googleApiKey = $app->get(ConfigurationEnum::GOOGLE_API_KEY->value)
            ?? $app->get(ConfigurationEnum::GEMINI_API_KEY->value);
        if (! empty($googleApiKey)) {
            $profiles['google:default'] = [
                'type' => 'api_key',
                'provider' => 'google',
                'key' => (string) $googleApiKey,
            ];
            $lastGood['google'] = 'google:default';
        }

        $anthropicApiKey = $app->get(ConfigurationEnum::ANTHROPIC_API_KEY->value);
        if (! empty($anthropicApiKey)) {
            $profiles['anthropic:default'] = [
                'type' => 'api_key',
                'provider' => 'anthropic',
                'key' => (string) $anthropicApiKey,
            ];
            $lastGood['anthropic'] = 'anthropic:default';
        }

        $config = [
            'version' => 1,
            'profiles' => ! empty($profiles) ? $profiles : (object) [],
            'lastGood' => ! empty($lastGood) ? $lastGood : (object) [],
            'usageStats' => (object) [],
        ];

        return (string) json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildChannelConfig(Agent $agent): array
    {
        $channels = [];

        $slackBotToken = $agent->get(CustomFieldEnum::SLACK_BOT_TOKEN->value);
        $slackAppToken = $agent->get(CustomFieldEnum::SLACK_APP_TOKEN->value);

        if (! empty($slackBotToken) && ! empty($slackAppToken)) {
            $channels['slack'] = [
                'enabled' => true,
                'mode' => 'socket',
                'allowBots' => true,
                'streaming' => 'partial',
                'nativeStreaming' => true,
                'botToken' => (string) $slackBotToken,
                'appToken' => (string) $slackAppToken,
                'dmPolicy' => 'open',
                'dm' => [
                    'enabled' => true,
                    'allowFrom' => ['*'],
                    'groupEnabled' => true,
                ],
                'groupPolicy' => 'open',
            ];
        }

        $telegramBotToken = $agent->get(CustomFieldEnum::TELEGRAM_BOT_TOKEN->value);

        if (! empty($telegramBotToken)) {
            $channels['telegram'] = [
                'enabled' => true,
                'botToken' => (string) $telegramBotToken,
                'dmPolicy' => 'pairing',
                'groupPolicy' => 'allowlist',
                'streaming' => 'partial',
            ];
        }

        return $channels;
    }

    public static function getSharedImageName(AppInterface $app): string
    {
        return (string) ($app->get(ConfigurationEnum::SHARED_IMAGE_NAME->value) ?? 'hermes-kanvas:latest');
    }

    public static function getSharedImageDir(AppInterface $app): string
    {
        return (string) ($app->get(ConfigurationEnum::SHARED_IMAGE_DIR->value) ?? '/opt/hermes-image');
    }

    /**
     * @return array<string, string>
     */
    public static function buildDefaultEnvironment(AppInterface $app): array
    {
        $envJson = $app->get(ConfigurationEnum::DEFAULT_ENVIRONMENT->value);

        if (! empty($envJson)) {
            $decoded = json_decode((string) $envJson, true);

            if (is_array($decoded)) {
                /** @var array<string, string> $decoded */
                return $decoded;
            }
        }

        return [];
    }
}
