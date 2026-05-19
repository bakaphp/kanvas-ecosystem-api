<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\Hermes\Enums\ConfigurationEnum;
use Kanvas\Connectors\Hermes\SshClient;
use Kanvas\Intelligence\AgentRuntime\Contracts\ProviderConfig;
use Kanvas\Intelligence\AgentRuntime\Services\BaseDockerComposeBuilder;
use Kanvas\Intelligence\Agents\Models\Agent;
use Override;
use Symfony\Component\Yaml\Yaml;

class DockerComposeBuilder extends BaseDockerComposeBuilder
{
    private const string TEMPLATES_DIR = __DIR__ . '/../Templates';

    /**
     * Compile-time defaults that land under `platforms.slack.extra` in the rendered YAML.
     *
     * Important: Hermes ignores fields placed directly under `platforms.slack` — they MUST
     * sit under the `extra` block to take effect. We confirmed this empirically when the
     * agent itself reported the previous direct-under-slack placement was silently dropped
     * and it kept threading replies despite our `reply_in_thread: false`.
     *
     * `reply_in_thread: false` flips Hermes's documented default (true) so bot replies land
     * in the main channel rather than starting a new thread.
     *
     * Per-app override (specify only the fields you want to flip — others keep these defaults):
     *     $app->set('hermes_slack_config', ['reply_in_thread' => true, 'require_mention' => false]);
     *
     * @var array<string, mixed>
     */
    private const array DEFAULT_SLACK_CONFIG = [
        'reply_in_thread' => false,
    ];

    // Compile-time fallback pin — used when the app-level `hermes_base_image` config is unset.
    //
    // Intentionally tracks `:latest`. Upstream Hermes (nousresearch/hermes-agent) has been
    // stable enough that we don't carry the silent-upstream-upgrade risk that bit us with
    // OpenClaw's May 2026 schema changes. If that ever changes — or for any app that needs
    // version-locking — set ConfigurationEnum::BASE_IMAGE on that app to lock it explicitly:
    //     $app->set('hermes_base_image', 'nousresearch/hermes-agent:<tag>');
    // The override path means we can pin a single app without locking everyone else.
    private const string HERMES_BASE_IMAGE = 'nousresearch/hermes-agent:latest';

    #[Override]
    protected function getProviderConfig(): ProviderConfig
    {
        return SshClient::makeProviderConfig();
    }

    #[Override]
    protected static function getTemplatesDir(): string
    {
        return self::TEMPLATES_DIR;
    }

    #[Override]
    protected function getDockerfileTemplateConfigKey(): string
    {
        return ConfigurationEnum::DOCKERFILE_TEMPLATE->value;
    }

    #[Override]
    protected function getSharedImageNameConfigKey(): string
    {
        return ConfigurationEnum::SHARED_IMAGE_NAME->value;
    }

    #[Override]
    protected function getSharedImageDirConfigKey(): string
    {
        return ConfigurationEnum::SHARED_IMAGE_DIR->value;
    }

    #[Override]
    protected function getDefaultEnvironmentConfigKey(): string
    {
        return ConfigurationEnum::DEFAULT_ENVIRONMENT->value;
    }

    #[Override]
    protected function getDefaultModelConfigKey(): string
    {
        return ConfigurationEnum::DEFAULT_MODEL->value;
    }

    #[Override]
    protected function getGeminiApiKeyConfigKey(): string
    {
        return ConfigurationEnum::GEMINI_API_KEY->value;
    }

    #[Override]
    protected function getGoogleApiKeyConfigKey(): string
    {
        return ConfigurationEnum::GOOGLE_API_KEY->value;
    }

    #[Override]
    protected function getAnthropicApiKeyConfigKey(): string
    {
        return ConfigurationEnum::ANTHROPIC_API_KEY->value;
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function getProviderEnvVarDefaults(): array
    {
        return ['HERMES_SKIP_SERVICE_CHECK' => 'true'];
    }

    #[Override]
    protected static function getDefaultBaseImage(): string
    {
        return self::HERMES_BASE_IMAGE;
    }

    #[Override]
    protected function getBaseImageConfigKey(): ?string
    {
        return ConfigurationEnum::BASE_IMAGE->value;
    }

    #[Override]
    public function getLocalImageNamePrefix(): string
    {
        return 'hermes-kanvas';
    }

    #[Override]
    public function buildRuntimeConfig(
        Agent $agent,
        string $gatewayToken,
        AppInterface $app,
        array $channelConfig = [],
    ): string {
        $rawModel = (string) ($app->get($this->getDefaultModelConfigKey()) ?? 'gemini-2.0-flash');
        $provider = $this->detectProvider($rawModel);
        $model = $this->normalizeModelName($rawModel, $provider);
        $baseUrl = $this->providerBaseUrl($provider);

        $platforms = [
            'slack' => $this->resolveSlackConfig($app),
        ];

        // Telegram block is opt-in: emit only when the app has explicit overrides.
        // Hermes activates Telegram from the env-var bot token alone, so leaving the
        // block out (vs. emitting an empty `platforms.telegram: {}`) keeps config.yaml
        // free of dead keys when no tuning is configured.
        $telegramConfig = $this->resolveTelegramConfig($app);
        if ($telegramConfig !== null) {
            $platforms['telegram'] = $telegramConfig;
        }

        $config = [
            'model' => [
                'default' => $model,
                'provider' => $provider,
                'base_url' => $baseUrl,
            ],
            'platforms' => $platforms,
        ];

        // inline=4 keeps the top-three levels (root → model/platforms → platforms.slack → fields)
        // expanded as a block mapping, which matches the docs' examples. indent=2 mirrors the
        // YAML the user has been editing by hand in /opt/data/config.yaml.
        return Yaml::dump($config, 4, 2);
    }

    /**
     * Merge per-app overrides with the compile-time defaults, then wrap the whole set in
     * an `extra:` block — that's the level Hermes actually reads platform customizations
     * from (fields placed directly under `platforms.slack` are silently ignored).
     *
     * The app config (`hermes_slack_config`) is the flat field map the user cares about;
     * the `extra` wrapping is a Hermes-side implementation detail we handle here so admins
     * don't need to know about it.
     *
     * @return array<string, mixed>  shaped `{extra: {...fields}}` ready for emission under `platforms.slack:`
     */
    private function resolveSlackConfig(AppInterface $app): array
    {
        $override = $app->get(ConfigurationEnum::SLACK_CONFIG->value);

        $fields = is_array($override)
            ? array_replace(self::DEFAULT_SLACK_CONFIG, $override)
            : self::DEFAULT_SLACK_CONFIG;

        return ['extra' => $fields];
    }

    /**
     * Same `extra:`-wrapping pattern as Slack — Hermes reads telegram customizations from
     * `platforms.telegram.extra` and ignores fields placed at the parent level. Returns
     * null when the app has no overrides set so we don't emit a dead `platforms.telegram:`
     * key in config.yaml (the env-var bot token alone is enough to activate the platform).
     *
     * Per-app shape (e.g. on `hermes_telegram_config`):
     *     [
     *         'require_mention' => true,
     *         'allow_from' => ['123456789'],
     *         'disable_link_previews' => true,
     *     ]
     *
     * @return array<string, mixed>|null  `{extra: {...fields}}` or null when not configured
     */
    private function resolveTelegramConfig(AppInterface $app): ?array
    {
        $override = $app->get(ConfigurationEnum::TELEGRAM_CONFIG->value);

        if (! is_array($override) || $override === []) {
            return null;
        }

        return ['extra' => $override];
    }

    /**
     * Provider-aware prefix stripping. Hermes's native adapters (gemini, anthropic) want
     * the bare model name (`gemini-3.1-pro-preview`, `claude-opus-4`) — the docs explicitly
     * warn against the prefixed form there. OpenRouter is the exception: it uses the full
     * `<actual-provider>/<model>` string (e.g. `anthropic/claude-opus-4`) because that's
     * how OpenRouter routes to the upstream backend. So we strip only when the prefix
     * matches the resolved native provider; openrouter keeps the full string.
     */
    private function normalizeModelName(string $model, string $provider): string
    {
        if ($provider === 'openrouter') {
            // OpenRouter routing requires the full <upstream>/<model> string. If the user
            // wrote `openrouter/foo/bar` explicitly, drop the redundant `openrouter/` so
            // we don't end up with a double-prefix.
            return str_starts_with($model, 'openrouter/') ? substr($model, strlen('openrouter/')) : $model;
        }

        $expectedPrefixes = match ($provider) {
            'gemini' => ['google/', 'gemini/'],
            'anthropic' => ['anthropic/'],
            default => [],
        };

        foreach ($expectedPrefixes as $prefix) {
            if (str_starts_with($model, $prefix)) {
                return substr($model, strlen($prefix));
            }
        }

        return $model;
    }

    /**
     * Pick the runtime provider from the model string. `openrouter/*` is explicit
     * opt-in to OpenRouter routing; otherwise we infer from prefix or model family.
     * If you want OpenRouter to handle a Claude model, prefix the name with `openrouter/`.
     */
    private function detectProvider(string $model): string
    {
        if (str_starts_with($model, 'openrouter/')) {
            return 'openrouter';
        }

        if (str_starts_with($model, 'anthropic/') || str_starts_with($model, 'claude-')) {
            return 'anthropic';
        }

        // google/*, gemini-*, gemma-*, or any unprefixed name we don't recognize — default
        // to gemini since that's the most common Hermes pairing.
        return 'gemini';
    }

    private function providerBaseUrl(string $provider): string
    {
        return match ($provider) {
            'anthropic' => 'https://api.anthropic.com',
            'openrouter' => 'https://openrouter.ai/api/v1',
            default => 'https://generativelanguage.googleapis.com/v1beta',
        };
    }

    /**
     * Hermes uses env vars for API keys — there's no auth-profiles.json equivalent.
     * Return null so the action skips the file entirely.
     */
    #[Override]
    public function getAuthProfilesTargetPath(string $providerDir, string $agentSlug): ?string
    {
        return null;
    }

    /**
     * SOUL.md lives at the root of `/opt/data` per the Hermes docs file tree
     * (`~/.hermes/SOUL.md`). Other workspace files (AGENTS.md, IDENTITY.md, USER.md,
     * TOOLS.md) are OpenClaw conventions with no documented Hermes home — skipping them
     * keeps `/opt/data` clean and avoids confusing the gateway.
     */
    #[Override]
    public function getWorkspaceFileTargetPath(string $providerDir, string $filename): ?string
    {
        return $filename === 'SOUL.md' ? $providerDir . '/' . $filename : null;
    }
}
