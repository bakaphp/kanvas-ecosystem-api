<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Actions;

use Kanvas\Connectors\Hermes\Services\DockerComposeBuilder;
use Kanvas\Connectors\Hermes\SshClient;
use Kanvas\Intelligence\AgentRuntime\Actions\BaseUpdateWorkspaceFilesAction;
use Kanvas\Intelligence\AgentRuntime\Contracts\ProviderConfig;
use Kanvas\Intelligence\AgentRuntime\SshClient as BaseSshClient;
use Override;

class UpdateWorkspaceFilesAction extends BaseUpdateWorkspaceFilesAction
{
    #[Override]
    protected function getProviderConfig(): ProviderConfig
    {
        return SshClient::makeProviderConfig();
    }

    #[Override]
    protected function createSshClient(): BaseSshClient
    {
        return SshClient::fromMachine($this->deployment->machine);
    }

    #[Override]
    protected function getDockerComposeBuilder(): DockerComposeBuilder
    {
        return new DockerComposeBuilder();
    }
}
