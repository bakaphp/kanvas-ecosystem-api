<?php

declare(strict_types=1);

namespace Kanvas\Connectors\AgentRuntime\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\AgentRuntime\Contracts\ProviderConfig;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

/**
 * Abstract base for generating all configuration files needed to run an agent in Docker.
 *
 * Output files:
 *  - Dockerfile        — base image + sudo setup (from Templates/Dockerfile or app override)
 *  - docker-compose.yml — gateway, socat proxy, and CLI containers
 *  - {provider}.json   — agent config: models, channels, gateway auth, tools, hooks
 *  - auth-profiles.json — LLM provider API keys (Google, Anthropic)
 *
 * Concrete subclasses (OpenClaw\Services\DockerComposeBuilder, Hermes\Services\DockerComposeBuilder)
 * implement the abstract getters that return their provider-specific ConfigurationEnum key strings
 * and CustomFieldEnum key strings.
 */
abstract class BaseDockerComposeBuilder
{
    private const string RUNTIME_VERSION = '2026.3.12';

    abstract protected function getProviderConfig(): ProviderConfig;

    abstract protected static function getTemplatesDir(): string;

    abstract protected function getDockerfileTemplateConfigKey(): string;

    abstract protected function getSharedImageNameConfigKey(): string;

    abstract protected function getSharedImageDirConfigKey(): string;

    abstract protected function getDefaultEnvironmentConfigKey(): string;

    abstract protected function getDefaultModelConfigKey(): string;

    abstract protected function getGeminiApiKeyConfigKey(): string;

    abstract protected function getGoogleApiKeyConfigKey(): string;

    abstract protected function getAnthropicApiKeyConfigKey(): string;

    abstract protected function getSlackBotTokenCustomFieldKey(): string;

    abstract protected function getSlackAppTokenCustomFieldKey(): string;

    abstract protected function getTelegramBotTokenCustomFieldKey(): string;

    public function buildDockerfile(AppInterface $app): string
    {
        $template = $app->get($this->getDockerfileTemplateConfigKey());

        if (! empty($template)) {
            return (string) $template;
        }

        return rtrim((string) file_get_contents(static::getTemplatesDir() . '/Dockerfile'));
    }

    public function buildEntrypoint(): string
    {
        return rtrim((string) file_get_contents(static::getTemplatesDir() . '/entrypoint.sh'));
    }

    /**
     * Provider-specific env var defaults written into docker-compose.yml.
     * Override to add e.g. ['OPENCLAW_SKIP_SERVICE_CHECK' => 'true'].
     *
     * @return array<string, string>
     */
    protected function getProviderEnvVarDefaults(): array
    {
        return [];
    }

    public function buildDockerCompose(
        AgentDeployment $deployment,
        string $gatewayToken,
        AppInterface $app,
        Agent $agent,
    ): string {
        $config  = $this->getProviderConfig();
        $envVars = $this->buildDefaultEnvironment($app);
        $envVars['NODE_ENV'] = $envVars['NODE_ENV'] ?? 'production';
        $envVars['KANVAS_DEPLOYMENT_ID'] = (string) $deployment->getId();

        foreach ($this->getProviderEnvVarDefaults() as $key => $default) {
            $envVars[$key] = $envVars[$key] ?? $default;
        }

        $slackBotToken = $agent->get($this->getSlackBotTokenCustomFieldKey());
        $slackAppToken = $agent->get($this->getSlackAppTokenCustomFieldKey());
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

        $template  = (string) file_get_contents(static::getTemplatesDir() . '/docker-compose.yml');
        $imageName = $this->getSharedImageName($app);

        return str_replace(
            ['{{CONTAINER_NAME}}', $config->dirPlaceholder, '{{GATEWAY_PORT}}', '{{PROXY_PORT}}', '{{ENV_LINES}}', '{{IMAGE_NAME}}', '{{IMAGE_DIR}}'],
            [
                $deployment->container_name,
                $deployment->home_directory . '/.' . $config->dotDir,
                (string) $deployment->gateway_port,
                (string) $deployment->proxy_port,
                $envLines,
                $imageName,
                $this->getSharedImageDir($app),
            ],
            $template,
        );
    }

    /**
     * Build the main runtime JSON config file (openclaw.json / hermes.json).
     *
     * @param array<string, mixed> $channelConfig
     */
    public function buildRuntimeConfig(
        Agent $agent,
        string $gatewayToken,
        AppInterface $app,
        array $channelConfig = [],
    ): string {
        $config        = $this->getProviderConfig();
        $slug          = $agent->slug;
        $homeDir       = $config->containerHomeDotDir;
        $model         = $app->get($this->getDefaultModelConfigKey()) ?? 'google/gemini-3.1-pro-preview';
        $geminiApiKey  = (string) ($app->get($this->getGeminiApiKeyConfigKey())
            ?? $app->get($this->getGoogleApiKeyConfigKey())
            ?? '');

        $authProfiles = [
            'openai-codex:default' => [
                'provider' => 'openai-codex',
                'mode'     => 'oauth',
            ],
        ];

        if ($geminiApiKey !== '') {
            $authProfiles['google:default'] = [
                'provider' => 'google',
                'mode'     => 'api_key',
            ];
        }

        $runtimeConfig = [
            'meta' => [
                'lastTouchedVersion' => self::RUNTIME_VERSION,
                'lastTouchedAt'      => now()->toISOString(),
            ],
            'wizard' => [
                'lastRunAt'      => now()->toISOString(),
                'lastRunVersion' => self::RUNTIME_VERSION,
                'lastRunCommand' => 'onboard',
                'lastRunMode'    => 'local',
            ],
            'auth' => [
                'profiles' => $authProfiles,
            ],
            'agents' => [
                'defaults' => [
                    'model' => [
                        'primary'   => $model,
                        'fallbacks' => [
                            'google/gemini-3.1-flash-lite-preview',
                            'google/gemini-3.1-pro-preview',
                        ],
                    ],
                    'models' => [
                        'google/gemini-2.5-pro'                => (object) [],
                        'google/gemini-3.1-flash-lite-preview' => (object) [],
                        'google/gemini-3.1-pro-preview'        => (object) [],
                    ],
                    'workspace' => $homeDir . '/workspace',
                ],
                'list' => [
                    [
                        'id'        => $slug,
                        'name'      => $agent->name,
                        'workspace' => $homeDir . '/workspace',
                        'agentDir'  => $homeDir . '/agents/' . $slug . '/agent',
                        'model'     => $model,
                    ],
                ],
            ],
            'tools' => [
                'profile' => 'full',
                'exec'    => ['security' => 'full'],
                'elevated' => [
                    'enabled'   => true,
                    'allowFrom' => [
                        'slack'    => ['*'],
                        'telegram' => ['*'],
                    ],
                ],
            ],
            'commands' => [
                'native'       => 'auto',
                'nativeSkills' => 'auto',
                'restart'      => true,
                'ownerDisplay' => 'raw',
            ],
            'session' => ['dmScope' => 'per-channel-peer'],
            'hooks'   => [
                'internal' => [
                    'enabled' => true,
                    'entries' => [
                        'boot-md'        => ['enabled' => true],
                        'session-memory' => ['enabled' => true],
                    ],
                ],
            ],
            'gateway' => [
                'port' => 18789,
                'mode' => 'local',
                'bind' => 'loopback',
                'auth' => [
                    'mode'  => 'token',
                    'token' => $gatewayToken,
                ],
                'http' => [
                    'endpoints' => ['responses' => ['enabled' => true]],
                ],
                'tailscale' => ['mode' => 'off', 'resetOnExit' => false],
                'nodes'     => [
                    'denyCommands' => [
                        'camera.snap', 'camera.clip', 'screen.record',
                        'contacts.add', 'calendar.add', 'reminders.add', 'sms.send',
                    ],
                ],
            ],
            'skills'  => ['entries' => (object) []],
            'plugins' => ['entries' => []],
        ];

        $pluginEntries = [];

        if (! empty($geminiApiKey)) {
            $pluginEntries['web-search'] = [
                'enabled' => true,
                'config'  => [
                    'webSearch' => [
                        'enabled'  => true,
                        'provider' => 'gemini',
                        'gemini'   => ['apiKey' => $geminiApiKey],
                    ],
                ],
            ];
            $runtimeConfig['skills']['entries'] = [
                'nano-banana-pro' => ['apiKey' => $geminiApiKey],
            ];
        }

        if (! empty($channelConfig)) {
            $runtimeConfig['channels'] = $channelConfig;
            if (isset($channelConfig['slack'])) {
                $pluginEntries['slack'] = ['enabled' => true];
            }
            if (isset($channelConfig['telegram'])) {
                $pluginEntries['telegram'] = ['enabled' => true];
            }
        }

        $runtimeConfig['plugins']['entries'] = ! empty($pluginEntries) ? $pluginEntries : (object) [];

        return (string) json_encode($runtimeConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function buildAuthProfiles(AppInterface $app): string
    {
        $profiles = [];
        $lastGood = [];

        $googleApiKey = $app->get($this->getGoogleApiKeyConfigKey())
            ?? $app->get($this->getGeminiApiKeyConfigKey());
        if (! empty($googleApiKey)) {
            $profiles['google:default'] = [
                'type'     => 'api_key',
                'provider' => 'google',
                'key'      => (string) $googleApiKey,
            ];
            $lastGood['google'] = 'google:default';
        }

        $anthropicApiKey = $app->get($this->getAnthropicApiKeyConfigKey());
        if (! empty($anthropicApiKey)) {
            $profiles['anthropic:default'] = [
                'type'     => 'api_key',
                'provider' => 'anthropic',
                'key'      => (string) $anthropicApiKey,
            ];
            $lastGood['anthropic'] = 'anthropic:default';
        }

        $config = [
            'version'    => 1,
            'profiles'   => ! empty($profiles) ? $profiles : (object) [],
            'lastGood'   => ! empty($lastGood) ? $lastGood : (object) [],
            'usageStats' => (object) [],
        ];

        return (string) json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildChannelConfig(Agent $agent): array
    {
        $channels = [];

        $slackBotToken = $agent->get($this->getSlackBotTokenCustomFieldKey());
        $slackAppToken = $agent->get($this->getSlackAppTokenCustomFieldKey());

        if (! empty($slackBotToken) && ! empty($slackAppToken)) {
            $channels['slack'] = [
                'enabled'         => true,
                'mode'            => 'socket',
                'allowBots'       => true,
                'streaming'       => 'partial',
                'nativeStreaming'  => true,
                'botToken'        => (string) $slackBotToken,
                'appToken'        => (string) $slackAppToken,
                'dmPolicy'        => 'open',
                'dm'              => [
                    'enabled'      => true,
                    'allowFrom'    => ['*'],
                    'groupEnabled' => true,
                ],
                'groupPolicy'     => 'open',
            ];
        }

        $telegramBotToken = $agent->get($this->getTelegramBotTokenCustomFieldKey());

        if (! empty($telegramBotToken)) {
            $channels['telegram'] = [
                'enabled'     => true,
                'botToken'    => (string) $telegramBotToken,
                'dmPolicy'    => 'pairing',
                'groupPolicy' => 'allowlist',
                'streaming'   => 'partial',
            ];
        }

        return $channels;
    }

    public function getSharedImageName(AppInterface $app): string
    {
        return (string) ($app->get($this->getSharedImageNameConfigKey()) ?? $this->getProviderConfig()->defaultSharedImageName);
    }

    public function getSharedImageDir(AppInterface $app): string
    {
        return (string) ($app->get($this->getSharedImageDirConfigKey()) ?? $this->getProviderConfig()->defaultSharedImageDir);
    }

    /**
     * @return array<string, string>
     */
    public function buildDefaultEnvironment(AppInterface $app): array
    {
        $envJson = $app->get($this->getDefaultEnvironmentConfigKey());

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
