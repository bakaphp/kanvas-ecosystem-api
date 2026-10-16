<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Intelligence\AgentRuntime\Contracts\ProviderConfig;
use Kanvas\Intelligence\AgentRuntime\Enums\AgentChannelTokenEnum;
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
 * Concrete subclasses (AgentRuntime\Services\DockerComposeBuilderService, Hermes\Services\DockerComposeBuilderService)
 * implement the abstract getters that return their provider-specific ConfigurationEnum key strings
 * and CustomFieldEnum key strings.
 */
abstract class BaseDockerComposeBuilderService
{
    private const string RUNTIME_VERSION = '2026.5.20';
    //private const string RUNTIME_VERSION = '2026.3.12';

    /**
     * Flash, not Pro: container agents run long sessions and OpenClaw re-sends the whole session every
     * turn, so the per-token price multiplies everything. Must be a model the pinned runtime's catalogue
     * knows — `gemini-3.8-flash` is newer than 2026.5.20 and would fail with "Unknown model".
     */
    public const string DEFAULT_MODEL = 'google/gemini-3-flash-preview';

    public const string CHEAP_MODEL = 'google/gemini-3.1-flash-lite-preview';

    /**
     * OpenClaw's default heartbeat is a full agent turn every 30m in the main session — ~100K tokens of
     * history re-read each time, mostly to answer HEARTBEAT_OK, since we write no HEARTBEAT.md. An
     * isolated, light-context run on the cheap model keeps the check for ~2-5K tokens.
     */
    private const array HEARTBEAT = [
        'every' => '2h',
        'model' => self::CHEAP_MODEL,
        'lightContext' => true,
        'isolatedSession' => true,
    ];

    /**
     * Compacts a session well before Gemini's 200K long-context price tier instead of near the 1M
     * ceiling, and prunes stale tool output from what is re-sent each turn.
     */
    private const int CONTEXT_TOKENS = 120_000;

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

    /**
     * Channel-token custom-field keys are runtime-agnostic — Slack/Telegram tokens are the same
     * value regardless of which runtime reads them. See {@see AgentChannelTokenEnum}. These used
     * to be abstract with per-connector `OPENCLAW_*` / `HERMES_*` overrides; merged here so a
     * single agent custom-field row services every runtime.
     */
    protected function getSlackBotTokenCustomFieldKey(): string
    {
        return AgentChannelTokenEnum::SLACK_BOT_TOKEN->value;
    }

    protected function getSlackAppTokenCustomFieldKey(): string
    {
        return AgentChannelTokenEnum::SLACK_APP_TOKEN->value;
    }

    protected function getTelegramBotTokenCustomFieldKey(): string
    {
        return AgentChannelTokenEnum::TELEGRAM_BOT_TOKEN->value;
    }

    /**
     * Provider-specific fallback for the upstream Docker base image when no app-level override
     * is set. e.g. `ghcr.io/phioranex/openclaw-docker:20260312` or `nousresearch/hermes-agent:latest`.
     * Used by getBaseImage() as the last resort.
     */
    abstract protected static function getDefaultBaseImage(): string;

    /**
     * App-config key for overriding the base image at runtime, or null if the provider doesn't
     * support per-app overrides. e.g. `'openclaw_base_image'`. Returning null means
     * getBaseImage() always returns getDefaultBaseImage() regardless of app config.
     */
    abstract protected function getBaseImageConfigKey(): ?string;

    /**
     * Prefix for the local image tag — combined with the base image's tag suffix to produce
     * `<prefix>:<tag>` (e.g. `openclaw-kanvas:20260312`). Makes the local image tag encode
     * which upstream it was built from so `ensureSharedImage()`'s existence check is
     * version-aware. e.g. `'openclaw-kanvas'`, `'hermes-kanvas'`.
     *
     * Public so update jobs can build provider-aware sed patterns when rewriting compose
     * `image:` lines (e.g. `image: openclaw-kanvas:*` → `image: openclaw-kanvas:<newTag>`).
     */
    abstract public function getLocalImageNamePrefix(): string;

    public function buildDockerfile(AppInterface $app): string
    {
        $template = $app->get($this->getDockerfileTemplateConfigKey());

        if (! empty($template)) {
            return (string) $template;
        }

        $raw = (string) file_get_contents(static::getTemplatesDir() . '/Dockerfile');

        // Substitute `{{BASE_IMAGE}}` if the template uses it (pinned providers); raw passthrough
        // otherwise (providers that haven't adopted the placeholder yet).
        return rtrim(str_replace('{{BASE_IMAGE}}', $this->getBaseImage($app), $raw));
    }

    /**
     * Resolve the upstream Docker image ref for new builds.
     *
     * Reads getBaseImageConfigKey() from the app config first, falls back to
     * getDefaultBaseImage(). The app-config path is the supported way to test a new upstream
     * version or roll forward without a code deploy.
     *
     * Nullable $app so ad-hoc inspect calls (CLI, tinker) without an app context still resolve
     * the safe default — production call sites always pass `$app`.
     */
    public function getBaseImage(?AppInterface $app = null): string
    {
        $key = $this->getBaseImageConfigKey();
        if ($app !== null && $key !== null) {
            $override = $app->get($key);
            if (! empty($override)) {
                return (string) $override;
            }
        }

        return static::getDefaultBaseImage();
    }

    /**
     * Tag portion of the resolved base image (everything after the last `:`).
     * Used by getSharedImageName() to build a version-tagged local ref, so the local
     * image tag records which upstream it was built from. Prevents the silent
     * reuse-of-stale-base bug that bites the `:latest` antipattern.
     */
    public function getBaseImageTag(?AppInterface $app = null): string
    {
        $image = $this->getBaseImage($app);
        $colonPos = strrpos($image, ':');

        return $colonPos === false ? 'latest' : substr($image, $colonPos + 1);
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

    /**
     * Default empty for CLI-only runtimes (OpenClaw). Hermes overrides to enable API_SERVER_*
     * with the gateway token as bearer. Merged after getProviderEnvVarDefaults() with first-wins
     * so per-app overrides still take precedence.
     *
     * @return array<string, string>
     */
    protected function getApiServerEnvVars(string $gatewayToken): array
    {
        return [];
    }

    public function buildDockerCompose(
        AgentDeployment $deployment,
        string $gatewayToken,
        AppInterface $app,
        Agent $agent,
    ): string {
        $config = $this->getProviderConfig();
        $envVars = $this->buildDefaultEnvironment($app);
        $envVars['NODE_ENV'] = $envVars['NODE_ENV'] ?? 'production';
        $envVars['KANVAS_DEPLOYMENT_ID'] = (string) $deployment->getId();

        foreach ($this->getProviderEnvVarDefaults() as $key => $default) {
            $envVars[$key] = $envVars[$key] ?? $default;
        }

        foreach ($this->getApiServerEnvVars($gatewayToken) as $key => $default) {
            $envVars[$key] = $envVars[$key] ?? $default;
        }

        // Emit LLM API keys as container env vars. OpenClaw also reads them from
        // {provider}.json's auth.profiles (still written by buildRuntimeConfig), so for
        // OpenClaw these env vars are belt-and-suspenders. Hermes's runtime *requires*
        // them in ~/.hermes/.env — without this its gateway logs:
        //   "No inference provider configured. ... set an API key in ~/.hermes/.env"
        // Additional keys (OPENROUTER_API_KEY, OPENAI_API_KEY, etc.) can be added per-app
        // via `<provider>_default_environment` — those flow through buildDefaultEnvironment().
        $geminiApiKey = $app->get($this->getGeminiApiKeyConfigKey());
        $googleApiKey = $app->get($this->getGoogleApiKeyConfigKey());
        $anthropicApiKey = $app->get($this->getAnthropicApiKeyConfigKey());
        if (! empty($geminiApiKey)) {
            $envVars['GEMINI_API_KEY'] = (string) $geminiApiKey;
        }
        if (! empty($googleApiKey)) {
            $envVars['GOOGLE_API_KEY'] = (string) $googleApiKey;
        }
        if (! empty($anthropicApiKey)) {
            $envVars['ANTHROPIC_API_KEY'] = (string) $anthropicApiKey;
        }

        $slackBotToken = $agent->get($this->getSlackBotTokenCustomFieldKey());
        $slackAppToken = $agent->get($this->getSlackAppTokenCustomFieldKey());
        if (! empty($slackBotToken)) {
            $envVars['SLACK_BOT_TOKEN'] = (string) $slackBotToken;
        }
        if (! empty($slackAppToken)) {
            $envVars['SLACK_APP_TOKEN'] = (string) $slackAppToken;
        }

        // Telegram: token + allow-list both have to be set or the Hermes gateway silently
        // denies every inbound message. We mirror the Slack pattern — the values live on the
        // agent's custom fields and the same row is reused across runtimes (the migrate flow
        // re-injects them on the destination via this builder, so no per-runtime drift).
        $telegramBotToken = $agent->get($this->getTelegramBotTokenCustomFieldKey());
        $telegramAllowedUsers = $agent->get(AgentChannelTokenEnum::TELEGRAM_ALLOWED_USERS->value);
        if (! empty($telegramBotToken)) {
            $envVars['TELEGRAM_BOT_TOKEN'] = (string) $telegramBotToken;
        }
        if (! empty($telegramAllowedUsers)) {
            $envVars['TELEGRAM_ALLOWED_USERS'] = (string) $telegramAllowedUsers;
        }

        $envLines = '';
        foreach ($envVars as $key => $value) {
            $envLines .= "      - {$key}={$value}\n";
        }

        $template = (string) file_get_contents(static::getTemplatesDir() . '/docker-compose.yml');
        $imageName = $this->getSharedImageName($app);

        // `{{BASE_IMAGE}}` is substituted here as well as in buildDockerfile() — some compose
        // templates reference the upstream image directly (e.g. OpenClaw's cli profile). Without
        // this, the literal `{{BASE_IMAGE}}` leaks into the rendered YAML and Docker's YAML
        // parser rejects the file with "cannot use 'map[string]interface{} {BASE_IMAGE:nil}' as
        // a map key" — flow-style mapping key from the unsubstituted braces. (Sentry: KANVAS-ECOSYSTEM-5JW.)
        return str_replace(
            [
                '{{CONTAINER_NAME}}',
                $config->dirPlaceholder,
                '{{GATEWAY_PORT}}',
                '{{PROXY_PORT}}',
                '{{ENV_LINES}}',
                '{{IMAGE_NAME}}',
                '{{IMAGE_DIR}}',
                '{{BASE_IMAGE}}',
            ],
            [
                $deployment->container_name,
                $deployment->home_directory . '/.' . $config->dotDir,
                (string) $deployment->gateway_port,
                (string) $deployment->proxy_port,
                $envLines,
                $imageName,
                $this->getSharedImageDir($app),
                $this->getBaseImage($app),
            ],
            $template,
        );
    }

    /**
     * The `agents.defaults` keys that decide what a container agent costs per day. Shared by launch
     * and by {@see costPatchFor()}, which pushes them to containers that are already running.
     *
     * @return array<string, mixed>
     */
    public static function costDefaults(): array
    {
        return [
            'heartbeat' => self::HEARTBEAT,
            'contextTokens' => self::CONTEXT_TOKENS,
            'compaction' => [
                'mode' => 'safeguard',
                'model' => self::CHEAP_MODEL,
            ],
            'contextPruning' => ['mode' => 'cache-ttl'],
        ];
    }

    /**
     * The partial runtime config that moves a *running* container onto the cheap defaults, in this
     * runtime's own serialization, or '' when it already has them. OpenClaw's config is JSON; Hermes
     * overrides this because its config is YAML and carries none of the session knobs.
     */
    public function costDefaultsPatch(string $currentConfig): string
    {
        $current = json_decode($currentConfig, true);

        return (string) json_encode(
            self::costPatchFor(is_array($current) ? $current : []),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * A partial openclaw.json for a running container: the cost defaults, plus a Flash model
     * wherever the live config still names a Pro one. A non-Pro model someone chose on purpose is
     * kept. `agents.list` is a list, which the config merge replaces wholesale, so it is rebuilt
     * from the live entries rather than from launch-time state.
     *
     * @param array<string, mixed> $current the container's live openclaw.json
     *
     * @return array<string, mixed>
     */
    public static function costPatchFor(array $current): array
    {
        $defaults = self::costDefaults();

        // `model` may be the string form ("provider/model") or `{ primary, fallbacks }`; the object is
        // always written back whole so a string is never merged into a primary-less object.
        $model = $current['agents']['defaults']['model'] ?? null;
        $primary = is_array($model) ? ($model['primary'] ?? null) : $model;

        $defaults['model'] = [
            'primary' => is_string($primary) && ! self::isProModel($primary) ? $primary : self::DEFAULT_MODEL,
            'fallbacks' => [self::CHEAP_MODEL],
        ];

        $defaults['models'] = [self::DEFAULT_MODEL => (object) []];

        $patch = ['agents' => ['defaults' => $defaults]];

        $list = $current['agents']['list'] ?? null;
        if (is_array($list) && $list !== []) {
            $patch['agents']['list'] = array_map(self::withoutProModel(...), array_values($list));
        }

        return $patch;
    }

    private static function withoutProModel(mixed $entry): mixed
    {
        if (! is_array($entry)) {
            return $entry;
        }

        $model = $entry['model'] ?? null;

        if (is_string($model) && self::isProModel($model)) {
            $entry['model'] = self::DEFAULT_MODEL;
        } elseif (is_array($model) && is_string($model['primary'] ?? null) && self::isProModel($model['primary'])) {
            $entry['model']['primary'] = self::DEFAULT_MODEL;
        }

        return $entry;
    }

    protected static function isProModel(string $model): bool
    {
        return str_contains($model, '-pro');
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
        $config = $this->getProviderConfig();
        $slug = $agent->slug;
        $homeDir = $config->containerHomeDotDir;
        $model = $app->get($this->getDefaultModelConfigKey()) ?? self::DEFAULT_MODEL;
        $geminiApiKey = (string) ($app->get($this->getGeminiApiKeyConfigKey())
            ?? $app->get($this->getGoogleApiKeyConfigKey())
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

        $runtimeConfig = [
            'meta' => [
                'lastTouchedVersion' => self::RUNTIME_VERSION,
                'lastTouchedAt' => now()->toISOString(),
            ],
            'wizard' => [
                'lastRunAt' => now()->toISOString(),
                'lastRunVersion' => self::RUNTIME_VERSION,
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
                        // No Pro fallback: a rate-limited Flash must not fail over to a 4-8x pricier model.
                        'fallbacks' => [self::CHEAP_MODEL],
                    ],
                    'models' => [
                        'google/gemini-2.5-pro' => (object) [],
                        'google/gemini-3-flash-preview' => (object) [],
                        'google/gemini-3.1-flash-lite-preview' => (object) [],
                        'google/gemini-3.1-pro-preview' => (object) [],
                    ],
                    ...self::costDefaults(),
                    'workspace' => $homeDir . '/workspace',
                ],
                'list' => [
                    [
                        'id' => $slug,
                        'name' => $agent->name,
                        'workspace' => $homeDir . '/workspace',
                        'agentDir' => $homeDir . '/agents/' . $slug . '/agent',
                        'model' => $model,
                    ],
                ],
            ],
            'tools' => [
                'profile' => 'full',
                'exec' => ['security' => 'full'],
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
            'session' => ['dmScope' => 'per-channel-peer'],
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
                    'endpoints' => ['responses' => ['enabled' => true]],
                ],
                'tailscale' => ['mode' => 'off', 'resetOnExit' => false],
                'nodes' => [
                    'denyCommands' => [
                        'camera.snap', 'camera.clip', 'screen.record',
                        'contacts.add', 'calendar.add', 'reminders.add', 'sms.send',
                    ],
                ],
            ],
            'skills' => ['entries' => (object) []],
            'plugins' => ['entries' => []],
        ];

        $pluginEntries = [];

        if (! empty($geminiApiKey)) {
            $pluginEntries['web-search'] = [
                'enabled' => true,
                'config' => [
                    'webSearch' => [
                        'enabled' => true,
                        'provider' => 'gemini',
                        'gemini' => ['apiKey' => $geminiApiKey],
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

    /**
     * Absolute path where `auth-profiles.json` is written, or null to skip the file entirely.
     *
     * Default targets OpenClaw's per-agent layout (`agents/<slug>/agent/auth-profiles.json`).
     * Hermes overrides to return null — it sources API keys exclusively from env vars,
     * so writing the file would just clutter `/opt/data` with unread bytes.
     */
    public function getAuthProfilesTargetPath(string $providerDir, string $agentSlug): ?string
    {
        return $providerDir . '/agents/' . $agentSlug . '/agent/auth-profiles.json';
    }

    /**
     * Absolute path where a single workspace file (SOUL.md, AGENTS.md, …) is written,
     * or null to skip that file. Default puts them in `$providerDir/workspace/$filename`
     * (OpenClaw's layout — referenced by its `agents.defaults.workspace` config field).
     *
     * Hermes overrides to put `SOUL.md` at the root of `$providerDir` (= `/opt/data` inside
     * the container, per the docs file tree) and skip the others, which have no documented
     * home in Hermes's data layout.
     */
    public function getWorkspaceFileTargetPath(string $providerDir, string $filename): ?string
    {
        return $providerDir . '/workspace/' . $filename;
    }

    public function buildAuthProfiles(AppInterface $app): string
    {
        $profiles = [];
        $lastGood = [];

        $googleApiKey = $app->get($this->getGoogleApiKeyConfigKey())
            ?? $app->get($this->getGeminiApiKeyConfigKey());
        if (! empty($googleApiKey)) {
            $profiles['google:default'] = [
                'type' => 'api_key',
                'provider' => 'google',
                'key' => (string) $googleApiKey,
            ];
            $lastGood['google'] = 'google:default';
        }

        $anthropicApiKey = $app->get($this->getAnthropicApiKeyConfigKey());
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
    public function buildChannelConfig(Agent $agent): array
    {
        $channels = [];

        $slackBotToken = $agent->get($this->getSlackBotTokenCustomFieldKey());
        $slackAppToken = $agent->get($this->getSlackAppTokenCustomFieldKey());

        if (! empty($slackBotToken) && ! empty($slackAppToken)) {
            // OpenClaw 2026.5.x tightened channels.slack — `streaming` is now an object
            // (was string `'partial'`), the legacy top-level `nativeStreaming` moved inside
            // as `nativeTransport`, and `allowBots` was removed. Gateway boot fails on the
            // old shape with: `channels.slack.streaming: invalid config: must be object`.
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
                'streaming' => [
                    'mode' => 'partial',
                    'nativeTransport' => true,
                ],
            ];
        }

        $telegramBotToken = $agent->get($this->getTelegramBotTokenCustomFieldKey());

        if (! empty($telegramBotToken)) {
            $channels['telegram'] = [
                'enabled' => true,
                'botToken' => (string) $telegramBotToken,
                'dmPolicy' => 'pairing',
                'groupPolicy' => 'allowlist',
                'streaming' => [
                    'mode' => 'partial',
                    'nativeTransport' => true,
                ],
            ];
        }

        return $channels;
    }

    /**
     * Local Docker image ref for the per-machine shared image. Honours per-app overrides
     * (SHARED_IMAGE_NAME) for admins who want a custom local ref; otherwise derives a
     * version-tagged ref from the base image: `<localPrefix>:<baseImageTag>`.
     * The version-tagged shape is the key to "ensureSharedImage" being version-aware —
     * bumping the pin yields a new tag, which triggers an automatic rebuild.
     */
    public function getSharedImageName(AppInterface $app): string
    {
        $override = $app->get($this->getSharedImageNameConfigKey());
        if (! empty($override)) {
            return (string) $override;
        }

        return $this->getLocalImageNamePrefix() . ':' . $this->getBaseImageTag($app);
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
        $stored = $app->get($this->getDefaultEnvironmentConfigKey());

        if (! is_array($stored)) {
            return [];
        }

        /** @var array<string, string> $stored */
        return $stored;
    }
}
