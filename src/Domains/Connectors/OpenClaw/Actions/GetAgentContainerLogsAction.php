<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Intelligence\AgentRuntime\Actions\BaseGetAgentContainerLogsAction;
use Kanvas\Intelligence\AgentRuntime\SshClient as BaseSshClient;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Override;

/**
 * OpenClaw concrete — supplies its SshClient subclass; lifecycle lives on the base.
 */
class GetAgentContainerLogsAction extends BaseGetAgentContainerLogsAction
{
    #[Override]
    protected function createSshClient(AgentMachine $machine): BaseSshClient
    {
        return SshClient::fromMachine($machine);
    }
}
