<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Actions;

use Kanvas\Intelligence\AgentRuntime\Actions\BaseLaunchAgentOnMachineAction;
use Kanvas\Intelligence\AgentRuntime\Contracts\ProviderConfig;
use Kanvas\Intelligence\AgentRuntime\Services\BaseDockerComposeBuilder;
use Kanvas\Intelligence\AgentRuntime\SshClient as BaseClient;
use Kanvas\Connectors\Hermes\Enums\ConfigurationEnum;
use Kanvas\Connectors\Hermes\Enums\CustomFieldEnum;
use Kanvas\Connectors\Hermes\Services\DockerComposeBuilder;
use Kanvas\Connectors\Hermes\SshClient;

/**
 * Hermes-specific agent launch — thin subclass that wires provider constants.
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
        return CustomFieldEnum::HERMES_GATEWAY_TOKEN->value;
    }

    protected function getDeploymentIdCustomFieldKey(): string
    {
        return CustomFieldEnum::HERMES_DEPLOYMENT_ID->value;
    }

    protected function getGatewayTokenConfigValue(): ?string
    {
        $value = $this->company->get(ConfigurationEnum::GATEWAY_TOKEN->value);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
