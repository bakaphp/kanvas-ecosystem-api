<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Services;

use Kanvas\Connectors\OpenClaw\Enums\ConfigurationEnum;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Intelligence\AgentRuntime\Contracts\ProviderConfig;
use Kanvas\Intelligence\AgentRuntime\Services\BaseDockerComposeBuilder;
use Override;

class DockerComposeBuilder extends BaseDockerComposeBuilder
{
    private const string TEMPLATES_DIR = __DIR__ . '/../Templates';

    // docker image version
    private const string OPENCLAW_BASE_IMAGE = 'ghcr.io/phioranex/openclaw-docker:20260522';

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
