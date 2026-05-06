<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Kanvas\Connectors\AgentRuntime\Actions\BaseLaunchAgentOnMachineAction;
use Kanvas\Connectors\AgentRuntime\Contracts\ProviderConfig;
use Kanvas\Connectors\AgentRuntime\Services\BaseDockerComposeBuilder;
use Kanvas\Connectors\AgentRuntime\SshClient as BaseClient;
use Kanvas\Connectors\OpenClaw\Enums\ConfigurationEnum;
use Kanvas\Connectors\OpenClaw\Enums\CustomFieldEnum;
use Kanvas\Connectors\OpenClaw\Services\DockerComposeBuilder;
use Kanvas\Connectors\OpenClaw\SshClient;

/**
 * OpenClaw-specific agent launch — thin subclass that wires provider constants.
 *
 * All deployment logic lives in BaseLaunchAgentOnMachineAction.
 */
class LaunchAgentOnMachineAction extends BaseLaunchAgentOnMachineAction
{
    private DockerComposeBuilder $builder;

    protected function getProviderConfig(): ProviderConfig
    {
        return SshClient::makeProviderConfig();
    }

    protected function createSshClient(): BaseClient
    {
        return SshClient::fromMachine($this->machine);
    }

    protected function getDockerComposeBuilder(): BaseDockerComposeBuilder
    {
        return $this->builder ??= new DockerComposeBuilder();
    }

    protected function getGatewayTokenCustomFieldKey(): string
    {
        return CustomFieldEnum::OPENCLAW_GATEWAY_TOKEN->value;
    }

    protected function getDeploymentIdCustomFieldKey(): string
    {
        return CustomFieldEnum::OPENCLAW_DEPLOYMENT_ID->value;
    }

    protected function getGatewayTokenConfigValue(): ?string
    {
        $value = $this->company->get(ConfigurationEnum::GATEWAY_TOKEN->value);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
