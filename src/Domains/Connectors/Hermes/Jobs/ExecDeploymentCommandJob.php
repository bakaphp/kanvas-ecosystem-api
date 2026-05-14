<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Jobs;

use Kanvas\Connectors\Hermes\SshClient;
use Kanvas\Intelligence\AgentRuntime\Jobs\BaseExecDeploymentCommandJob;
use Kanvas\Intelligence\AgentRuntime\SshClient as BaseSshClient;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Override;

class ExecDeploymentCommandJob extends BaseExecDeploymentCommandJob
{
    #[Override]
    protected function createSshClient(AgentMachine $machine): BaseSshClient
    {
        return SshClient::fromMachine($machine);
    }
}
