<?php

declare(strict_types=1);

namespace Kanvas\Connectors\AgentRuntime\Actions;

use Kanvas\Connectors\AgentRuntime\Enums\ConfigurationEnum;
use Kanvas\Connectors\AgentRuntime\Enums\CustomFieldEnum;
use Kanvas\Connectors\AgentRuntime\Services\DockerComposeBuilder;
use Kanvas\Connectors\AgentRuntime\SshClient;
use Kanvas\Intelligence\AgentRuntime\Actions\BaseLaunchAgentOnMachineAction;
use Kanvas\Intelligence\AgentRuntime\Contracts\ProviderConfig;
use Kanvas\Intelligence\AgentRuntime\Services\BaseDockerComposeBuilder;
use Kanvas\Intelligence\AgentRuntime\SshClient as BaseClient;
use Override;

/**
 * OpenClaw-specific agent launch — thin subclass that wires provider constants.
 *
 * All deployment logic lives in BaseLaunchAgentOnMachineAction.
 */
class LaunchAgentOnMachineAction extends BaseLaunchAgentOnMachineAction
{
    private DockerComposeBuilder $builder;

    #[Override]
    protected function getProviderConfig(): ProviderConfig
    {
        return SshClient::makeProviderConfig();
    }

    #[Override]
    protected function createSshClient(): BaseClient
    {
        return SshClient::fromMachine($this->machine);
    }

    #[Override]
    protected function getDockerComposeBuilder(): BaseDockerComposeBuilder
    {
        return $this->builder ??= new DockerComposeBuilder();
    }
}
