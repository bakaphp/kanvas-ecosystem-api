<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Actions;

use Kanvas\Connectors\Hermes\Enums\CustomFieldEnum;
use Kanvas\Connectors\Hermes\SshClient;
use Kanvas\Intelligence\AgentRuntime\Actions\BaseTerminateAgentOnMachineAction;
use Kanvas\Intelligence\AgentRuntime\Contracts\ProviderConfig;
use Kanvas\Intelligence\AgentRuntime\SshClient as BaseSshClient;
use Override;

/**
 * Hermes-specific terminate — thin subclass that wires the Hermes provider classes.
 * All termination logic (including idempotent dir handling) lives in BaseTerminateAgentOnMachineAction.
 */
class TerminateAgentOnMachineAction extends BaseTerminateAgentOnMachineAction
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
    protected function afterTerminate(BaseSshClient $client): void
    {
        $this->deployment->agent?->del(CustomFieldEnum::HERMES_DEPLOYMENT_ID->value);
    }
}
