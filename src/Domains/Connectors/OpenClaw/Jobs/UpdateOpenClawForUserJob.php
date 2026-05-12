<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Jobs;

use Kanvas\Connectors\OpenClaw\Services\DockerComposeBuilder;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Intelligence\AgentRuntime\Contracts\ProviderConfig;
use Kanvas\Intelligence\AgentRuntime\Jobs\BaseUpdateAgentForUserJob;
use Kanvas\Intelligence\AgentRuntime\Services\BaseDockerComposeBuilder;
use Kanvas\Intelligence\AgentRuntime\SshClient as BaseSshClient;
use Override;

/**
 * OpenClaw-specific update job — thin subclass that wires the OpenClaw provider classes.
 * All update logic lives in BaseUpdateAgentForUserJob.
 */
class UpdateOpenClawForUserJob extends BaseUpdateAgentForUserJob
{
    #[Override]
    protected function getProviderConfig(): ProviderConfig
    {
        return SshClient::makeProviderConfig();
    }

    #[Override]
    protected function createBuilder(): BaseDockerComposeBuilder
    {
        return new DockerComposeBuilder();
    }

    #[Override]
    protected function createSshClient(): BaseSshClient
    {
        return SshClient::fromMachine($this->machine);
    }
}
