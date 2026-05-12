<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Services;

use Kanvas\Connectors\Hermes\Enums\ConfigurationEnum;
use Kanvas\Connectors\Hermes\Enums\CustomFieldEnum;
use Kanvas\Connectors\Hermes\SshClient;
use Kanvas\Intelligence\AgentRuntime\Contracts\ProviderConfig;
use Kanvas\Intelligence\AgentRuntime\Services\BaseDockerComposeBuilder;
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
}
