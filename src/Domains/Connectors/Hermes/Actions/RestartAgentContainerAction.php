<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Actions;

use Kanvas\Connectors\Hermes\SshClient;
use Kanvas\Intelligence\AgentRuntime\Actions\BaseRestartAgentContainerAction;
use Kanvas\Intelligence\AgentRuntime\SshClient as BaseSshClient;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Override;

class RestartAgentContainerAction extends BaseRestartAgentContainerAction
{
    #[Override]
    protected function createSshClient(AgentMachine $machine): BaseSshClient
    {
        return SshClient::fromMachine($machine);
    }
}
