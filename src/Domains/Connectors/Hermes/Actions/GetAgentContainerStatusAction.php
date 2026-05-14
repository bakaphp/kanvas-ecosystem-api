<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Actions;

use Kanvas\Connectors\Hermes\SshClient;
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
