<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\Hermes\Enums\ConfigurationEnum;
use Kanvas\Connectors\Hermes\Enums\CustomFieldEnum;
use Kanvas\Connectors\Hermes\SshClient;
use Kanvas\Intelligence\AgentRuntime\Contracts\ProviderConfig;
use Kanvas\Intelligence\AgentRuntime\Services\BaseDockerComposeBuilder;
use Kanvas\Intelligence\Agents\Models\Agent;
use Override;

/**
 * Hermes-specific DockerComposeBuilder — thin subclass that wires provider config keys.
 *
 * All file-generation logic (including base-image pinning, version-tagged local image refs,
 * and Dockerfile substitution) lives in BaseDockerComposeBuilder. This class just supplies
 * provider-specific constants via the abstract getters.
 */
class DockerComposeBuilder extends BaseDockerComposeBuilder
{
    private const string TEMPLATES_DIR = __DIR__ . '/../Templates';

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

    #[Override]
    protected function getSlackBotTokenCustomFieldKey(): string
    {
        return CustomFieldEnum::SLACK_BOT_TOKEN->value;
    }

    #[Override]
    protected function getSlackAppTokenCustomFieldKey(): string
    {
        return CustomFieldEnum::SLACK_APP_TOKEN->value;
    }

    #[Override]
    protected function getTelegramBotTokenCustomFieldKey(): string
    {
        return CustomFieldEnum::TELEGRAM_BOT_TOKEN->value;
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

    /**
     * Hermes uses a flat YAML config at `/opt/data/config.yaml` (NOT the nested JSON shape
     * OpenClaw uses). Documented at https://hermes-agent.nousresearch.com/docs/user-guide/configuration
     * and https://hermes-agent.nousresearch.com/docs/guides/google-gemini.
     *
     * Hermes resolves API keys purely from env vars (we emit those in buildDockerCompose),
     * so there's no `auth.profiles` section — just `model`. The model name MUST be the
     * native form when `provider: gemini` (e.g. `gemini-3.1-pro-preview`), not the
     * OpenRouter-style `google/gemini-3.1-pro-preview` — the docs explicitly warn against
     * the prefixed form with the native gemini provider. We strip any provider prefix
     * for callers who use the OpenRouter-style strings.
     *
     * Provider routing is derived from the configured model name:
     *  - `anthropic/*` or `claude-*` → provider=anthropic, api.anthropic.com
     *  - `openrouter/*`              → provider=openrouter, openrouter.ai/api/v1
     *  - anything else (including `google/*`, `gemini-*`) → provider=gemini, Google's v1beta
     *
     * Hermes's entrypoint copies a default config.yaml if none exists; ours overwrites
     * that default. If we ever need finer-grained config (channels.slack.dmPolicy, hooks,
     * etc.) it gets added here as additional YAML sections.
     *
     * @param array<string, mixed> $channelConfig unused for Hermes — channels are configured
     *                                            via env vars (SLACK_BOT_TOKEN etc.) which
     *                                            buildDockerCompose already emits.
     */
    #[Override]
    public function buildRuntimeConfig(
        Agent $agent,
        string $gatewayToken,
        AppInterface $app,
        array $channelConfig = [],
    ): string {
        $rawModel = (string) ($app->get($this->getDefaultModelConfigKey()) ?? 'gemini-3.1-pro-preview');
        $provider = $this->detectProvider($rawModel);
        $model = $this->normalizeModelName($rawModel);
        $baseUrl = $this->providerBaseUrl($provider);

        return <<<YAML
            model:
              default: {$model}
              provider: {$provider}
              base_url: {$baseUrl}

            YAML;
    }

    /**
     * Strip any leading `<provider>/` prefix so the model field matches what Hermes's
     * native adapters expect.
     */
    private function normalizeModelName(string $model): string
    {
        $slashPos = strpos($model, '/');

        return $slashPos === false ? $model : substr($model, $slashPos + 1);
    }

    private function detectProvider(string $model): string
    {
        if (str_starts_with($model, 'anthropic/') || str_starts_with($model, 'claude-')) {
            return 'anthropic';
        }

        if (str_starts_with($model, 'openrouter/')) {
            return 'openrouter';
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
}
