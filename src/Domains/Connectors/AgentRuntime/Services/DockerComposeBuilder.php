<?php

declare(strict_types=1);

namespace Kanvas\Connectors\AgentRuntime\Services;

use Kanvas\Connectors\AgentRuntime\Enums\ConfigurationEnum;
use Kanvas\Connectors\AgentRuntime\Enums\CustomFieldEnum;
use Kanvas\Connectors\AgentRuntime\SshClient;
use Kanvas\Intelligence\AgentRuntime\Contracts\ProviderConfig;
use Kanvas\Intelligence\AgentRuntime\Services\BaseDockerComposeBuilder;
use Override;

/**
 * OpenClaw-specific DockerComposeBuilder — thin subclass that wires provider config keys.
 *
 * All file-generation logic (including base-image pinning, version-tagged local image refs,
 * and Dockerfile substitution) lives in BaseDockerComposeBuilder. This class just supplies
 * provider-specific constants via the abstract getters.
 */
class DockerComposeBuilder extends BaseDockerComposeBuilder
{
    private const string TEMPLATES_DIR = __DIR__ . '/../Templates';

    // Compile-time fallback pin — used when the app-level `openclaw_base_image` config is unset.
    // Pinned to the 20260312 build (ships OpenClaw 2026.3.8 npm) because the May 2026 releases
    // (2026.5.x) tightened the channels.slack schema and break prod deploys.
    //
    // To bump WITHOUT a code deploy, set ConfigurationEnum::BASE_IMAGE on the app, e.g.:
    //     $app->set('openclaw_base_image', 'ghcr.io/phioranex/openclaw-docker:20260601');
    // Then run the openclawUpdateMachineContainers mutation against the machines you want forward.
    // Update this constant when changing the default for apps without the override.
    private const string OPENCLAW_BASE_IMAGE = 'ghcr.io/phioranex/openclaw-docker:20260312';

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
        return ['OPENCLAW_SKIP_SERVICE_CHECK' => 'true'];
    }

    #[Override]
    protected static function getDefaultBaseImage(): string
    {
        return self::OPENCLAW_BASE_IMAGE;
    }

    #[Override]
    protected function getBaseImageConfigKey(): ?string
    {
        return ConfigurationEnum::BASE_IMAGE->value;
    }

    #[Override]
    public function getLocalImageNamePrefix(): string
    {
        return 'openclaw-kanvas';
    }
}
