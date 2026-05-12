<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\OpenClaw\Enums\ConfigurationEnum;
use Kanvas\Connectors\OpenClaw\Enums\CustomFieldEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

/**
 * Generates all configuration files needed to run an OpenClaw agent in Docker.
 *
 * Output files:
 *  - Dockerfile        — base image + sudo setup (from Templates/Dockerfile or app override)
 *  - docker-compose.yml — gateway, socat proxy, and CLI containers (from Templates/docker-compose.yml)
 *  - openclaw.json     — agent config: models, channels (Slack), gateway auth, tools, hooks
 *  - auth-profiles.json — LLM provider API keys (Google, Anthropic)
 *
 * App-level settings (ConfigurationEnum): LLM keys, model defaults, Dockerfile template
 * Agent-level settings (CustomFieldEnum): Slack tokens (each agent has its own Slack app)
 *
 * Bump OPENCLAW_VERSION when upgrading the openclaw-docker image.
 */
class DockerComposeBuilder
{
    private const string TEMPLATES_DIR = __DIR__ . '/../Templates';
    private const string OPENCLAW_VERSION = '2026.5.3-1';

    private const string OPENCLAW_BASE_IMAGE = 'ghcr.io/phioranex/openclaw-docker:20260504';

    public static function buildDockerfile(AppInterface $app): string
    {
        $template = $app->get(ConfigurationEnum::DOCKERFILE_TEMPLATE->value);

        if (! empty($template)) {
            return (string) $template;
        }

        $raw = (string) file_get_contents(self::TEMPLATES_DIR . '/Dockerfile');

        return rtrim(str_replace('{{BASE_IMAGE}}', self::OPENCLAW_BASE_IMAGE, $raw));
    }

    public static function getBaseImage(): string
    {
        return self::OPENCLAW_BASE_IMAGE;
    }

    /**
     * Tag portion of OPENCLAW_BASE_IMAGE (everything after the last `:`).
     * Used as the local image tag so each pin yields a distinct, content-addressable
     * `openclaw-kanvas:<tag>` — making "is the right version already built?" a
     * trivial `docker image inspect` check and preventing the silent-reuse-of-stale-base
     * bug that comes with the `:latest` tag.
     */
    public static function getBaseImageTag(): string
    {
        $colonPos = strrpos(self::OPENCLAW_BASE_IMAGE, ':');

        return $colonPos === false ? 'latest' : substr(self::OPENCLAW_BASE_IMAGE, $colonPos + 1);
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
        $envVars['OPENCLAW_SKIP_SERVICE_CHECK'] = $envVars['OPENCLAW_SKIP_SERVICE_CHECK'] ?? 'true';

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
            [
                '{{CONTAINER_NAME}}',
                '{{OPENCLAW_DIR}}',
                '{{GATEWAY_PORT}}',
                '{{PROXY_PORT}}',
                '{{ENV_LINES}}',
                '{{IMAGE_NAME}}',
                '{{IMAGE_DIR}}',
                '{{BASE_IMAGE}}',
            ],
            [
                $deployment->container_name,
                $deployment->home_directory . '/.openclaw',
                (string) $deployment->gateway_port,
                (string) $deployment->proxy_port,
                $envLines,
                $imageName,
                self::getSharedImageDir($app),
                self::OPENCLAW_BASE_IMAGE,
            ],
            $template,
        );
    }

    /**
     * @param array<string, mixed> $channelConfig Optional channel config (e.g. slack bot/app tokens)
     */
    public static function buildOpenClawConfig(
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
                'mode' => 'api_key',
            ];
        }

        $config = [
            'meta' => [
                'lastTouchedVersion' => self::OPENCLAW_VERSION,
                'lastTouchedAt' => now()->toISOString(),
            ],
            'wizard' => [
                'lastRunAt' => now()->toISOString(),
                'lastRunVersion' => self::OPENCLAW_VERSION,
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
                        'google/gemini-2.5-pro' => (object) [],
                        'google/gemini-3.1-flash-lite-preview' => (object) [],
                        'google/gemini-3.1-pro-preview' => (object) [],
                    ],
                    'workspace' => '/home/node/.openclaw/workspace',
                ],
                'list' => [
                    [
                        'id' => $slug,
                        'name' => $agent->name,
                        'workspace' => '/home/node/.openclaw/workspace',
                        'agentDir' => '/home/node/.openclaw/agents/' . $slug . '/agent',
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
            // OpenClaw 2026.5.7+ tightened channels.slack: `streaming` is now an object (was a
            // string), `additionalProperties` is false. We drop the kanvas-specific keys
            // (`allowBots`, `streaming`, `nativeStreaming`) that were added to defend against
            // earlier-version defaults — the new gateway applies its own defaults for these.
            // Re-introduce a proper object-shape `streaming` once the schema is confirmed.
            $channels['slack'] = [
                'enabled' => true,
                'mode' => 'socket',
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
            // See note above on `channels.slack.streaming` — same applies to telegram.
            $channels['telegram'] = [
                'enabled' => true,
                'botToken' => (string) $telegramBotToken,
                'dmPolicy' => 'pairing',
                'groupPolicy' => 'allowlist',
            ];
        }

        return $channels;
    }

    public static function getSharedImageName(AppInterface $app): string
    {
        // Default to a version-tagged ref (`openclaw-kanvas:<pinned-tag>`) so the image tag
        // encodes which upstream base it was built from. A per-app override is honoured as-is
        // — admins setting SHARED_IMAGE_NAME are responsible for including a stable tag.
        $override = $app->get(ConfigurationEnum::SHARED_IMAGE_NAME->value);
        if (! empty($override)) {
            return (string) $override;
        }

        return 'openclaw-kanvas:' . self::getBaseImageTag();
    }

    public static function getSharedImageDir(AppInterface $app): string
    {
        return (string) ($app->get(ConfigurationEnum::SHARED_IMAGE_DIR->value) ?? '/opt/openclaw-image');
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
