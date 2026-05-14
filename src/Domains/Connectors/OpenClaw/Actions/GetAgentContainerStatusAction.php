<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Intelligence\AgentRuntime\Actions\BaseGetAgentContainerStatusAction;
use Kanvas\Intelligence\AgentRuntime\SshClient as BaseSshClient;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Override;

class GetAgentContainerStatusAction extends BaseGetAgentContainerStatusAction
{
    #[Override]
    protected function createSshClient(AgentMachine $machine): BaseSshClient
    {
        return SshClient::fromMachine($machine);
    }
}
