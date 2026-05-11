<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Services;

use Kanvas\Intelligence\AgentRuntime\Contracts\ProviderConfig;
use Kanvas\Intelligence\AgentRuntime\Services\BaseDockerComposeBuilder;
use Kanvas\Connectors\OpenClaw\Enums\ConfigurationEnum;
use Kanvas\Connectors\OpenClaw\Enums\CustomFieldEnum;
use Kanvas\Connectors\OpenClaw\SshClient;

/**
 * OpenClaw-specific DockerComposeBuilder — thin subclass that wires provider config keys.
 *
 * All file-generation logic lives in BaseDockerComposeBuilder.
 *
 * BC note: the old class used static methods (buildOpenClawConfig, buildDockerCompose, etc.).
 * Callers that used those static methods (RebuildSharedImageAction, MigrateAgentWorkspaceAction)
 * should be updated to instantiate this class and call the instance methods instead.
 */
class DockerComposeBuilder extends BaseDockerComposeBuilder
{
    private const string TEMPLATES_DIR = __DIR__ . '/../Templates';

    protected function getProviderConfig(): ProviderConfig
    {
        return SshClient::makeProviderConfig();
    }

    protected static function getTemplatesDir(): string
    {
        return self::TEMPLATES_DIR;
    }

    protected function getDockerfileTemplateConfigKey(): string
    {
        return ConfigurationEnum::DOCKERFILE_TEMPLATE->value;
    }

    protected function getSharedImageNameConfigKey(): string
    {
        return ConfigurationEnum::SHARED_IMAGE_NAME->value;
    }

    protected function getSharedImageDirConfigKey(): string
    {
        return ConfigurationEnum::SHARED_IMAGE_DIR->value;
    }

    protected function getDefaultEnvironmentConfigKey(): string
    {
        return ConfigurationEnum::DEFAULT_ENVIRONMENT->value;
    }

    protected function getDefaultModelConfigKey(): string
    {
        return ConfigurationEnum::DEFAULT_MODEL->value;
    }

    protected function getGeminiApiKeyConfigKey(): string
    {
        return ConfigurationEnum::GEMINI_API_KEY->value;
    }

    protected function getGoogleApiKeyConfigKey(): string
    {
        return ConfigurationEnum::GOOGLE_API_KEY->value;
    }

    protected function getAnthropicApiKeyConfigKey(): string
    {
        return ConfigurationEnum::ANTHROPIC_API_KEY->value;
    }

    protected function getSlackBotTokenCustomFieldKey(): string
    {
        return CustomFieldEnum::SLACK_BOT_TOKEN->value;
    }

    protected function getSlackAppTokenCustomFieldKey(): string
    {
        return CustomFieldEnum::SLACK_APP_TOKEN->value;
    }

    protected function getTelegramBotTokenCustomFieldKey(): string
    {
        return CustomFieldEnum::TELEGRAM_BOT_TOKEN->value;
    }

    /**
     * @return array<string, string>
     */
    protected function getProviderEnvVarDefaults(): array
    {
        return ['OPENCLAW_SKIP_SERVICE_CHECK' => 'true'];
    }
}
