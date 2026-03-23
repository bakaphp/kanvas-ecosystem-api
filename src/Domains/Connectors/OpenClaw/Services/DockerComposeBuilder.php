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
    private const string OPENCLAW_VERSION = '2026.3.12';

    public static function buildDockerfile(AppInterface $app): string
    {
        $template = $app->get(ConfigurationEnum::DOCKERFILE_TEMPLATE->value);

        if (! empty($template)) {
            return (string) $template;
        }

        return rtrim((string) file_get_contents(self::TEMPLATES_DIR . '/Dockerfile'));
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

        return str_replace(
            ['{{CONTAINER_NAME}}', '{{OPENCLAW_DIR}}', '{{GATEWAY_PORT}}', '{{PROXY_PORT}}', '{{ENV_LINES}}'],
            [
                $deployment->container_name,
                $deployment->home_directory . '/.openclaw',
                (string) $deployment->gateway_port,
                (string) $deployment->proxy_port,
                $envLines,
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
        $model = $app->get(ConfigurationEnum::DEFAULT_MODEL->value) ?? 'google/gemini-2.5-pro';
        $geminiApiKey = $app->get(ConfigurationEnum::GEMINI_API_KEY->value) ?? '';

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
                'profiles' => [
                    'openai-codex:default' => [
                        'provider' => 'openai-codex',
                        'mode' => 'oauth',
                    ],
                ],
            ],
            'agents' => [
                'defaults' => [
                    'model' => [
                        'primary' => $model,
                        'fallbacks' => [
                            'google/gemini-3.1-flash-lite-preview',
                            'google/gemini-3.1-pro-preview',
                            'google-vertex/gemini-2.5-pro',
                            'google-vertex/gemini-3-flash-preview',
                        ],
                    ],
                    'models' => [
                        'google/gemini-2.5-pro' => (object) [],
                        'google/gemini-3.1-flash-lite-preview' => (object) [],
                        'google/gemini-3.1-pro-preview' => (object) [],
                        'google-vertex/gemini-2.5-pro' => (object) [],
                        'google-vertex/gemini-3-flash-preview' => (object) [],
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
                'profile' => 'coding',
                'exec' => [
                    'security' => 'full',
                ],
                'elevated' => [
                    'enabled' => true,
                    'allowFrom' => [
                        'slack' => ['*'],
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
                'entries' => (object) [],
            ],
        ];

        if (! empty($geminiApiKey)) {
            $config['tools']['web'] = [
                'search' => [
                    'enabled' => true,
                    'provider' => 'gemini',
                    'gemini' => [
                        'apiKey' => $geminiApiKey,
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
                $config['plugins']['entries'] = [
                    'slack' => ['enabled' => true],
                ];
            }
        }

        return (string) json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public static function buildAuthProfiles(AppInterface $app): string
    {
        $profiles = [];
        $lastGood = [];

        $googleApiKey = $app->get(ConfigurationEnum::GOOGLE_API_KEY->value);
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
        $slackBotToken = $agent->get(CustomFieldEnum::SLACK_BOT_TOKEN->value);
        $slackAppToken = $agent->get(CustomFieldEnum::SLACK_APP_TOKEN->value);

        if (empty($slackBotToken) || empty($slackAppToken)) {
            return [];
        }

        return [
            'slack' => [
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
            ],
        ];
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
