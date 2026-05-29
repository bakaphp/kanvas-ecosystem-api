<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Jobs;

use Kanvas\Connectors\OpenClaw\Services\DockerComposeBuilderService;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Intelligence\AgentRuntime\Contracts\ProviderConfig;
use Kanvas\Intelligence\AgentRuntime\Jobs\BaseUpdateAgentForUserJob;
use Kanvas\Intelligence\AgentRuntime\Services\BaseDockerComposeBuilderService;
use Kanvas\Intelligence\AgentRuntime\SshClient as BaseSshClient;
use Override;

class UpdateOpenClawForUserJob extends BaseUpdateAgentForUserJob
{
    #[Override]
    protected function getProviderConfig(): ProviderConfig
    {
        return SshClient::makeProviderConfig();
    }

    #[Override]
    protected function createBuilder(): BaseDockerComposeBuilderService
    {
        return new DockerComposeBuilderService();
    }

    #[Override]
    protected function createSshClient(): BaseSshClient
    {
        return SshClient::fromMachine($this->machine);
    }
}
