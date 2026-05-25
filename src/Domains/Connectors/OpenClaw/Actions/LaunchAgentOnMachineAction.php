<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Kanvas\Connectors\OpenClaw\Services\DockerComposeBuilderService;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Intelligence\AgentRuntime\Actions\BaseLaunchAgentOnMachineAction;
use Kanvas\Intelligence\AgentRuntime\Contracts\ProviderConfig;
use Kanvas\Intelligence\AgentRuntime\Services\BaseDockerComposeBuilderService;
use Kanvas\Intelligence\AgentRuntime\SshClient as BaseClient;
use Override;

/**
 * OpenClaw-specific agent launch — thin subclass that wires provider constants.
 *
 * All deployment logic lives in BaseLaunchAgentOnMachineAction.
 */
class LaunchAgentOnMachineAction extends BaseLaunchAgentOnMachineAction
{
    private DockerComposeBuilderService $builder;

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
    protected function getDockerComposeBuilder(): BaseDockerComposeBuilderService
    {
        return $this->builder ??= new DockerComposeBuilderService();
    }
}
