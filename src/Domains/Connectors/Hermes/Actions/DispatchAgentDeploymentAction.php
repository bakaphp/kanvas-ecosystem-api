<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Intelligence\AgentRuntime\Actions\BaseDispatchAgentDeploymentAction;
use Kanvas\Intelligence\AgentRuntime\Contracts\ProviderConfig;
use Kanvas\Connectors\Hermes\Jobs\LaunchAgentJob;
use Kanvas\Connectors\Hermes\SshClient;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * Hermes-specific dispatch — thin subclass that wires the provider config and launch job.
 *
 * All deployment-record logic lives in BaseDispatchAgentDeploymentAction.
 */
class DispatchAgentDeploymentAction extends BaseDispatchAgentDeploymentAction
{
    protected function getProviderConfig(): ProviderConfig
    {
        return SshClient::makeProviderConfig();
    }

    protected function dispatchLaunchJob(
        Agent $agent,
        AgentMachine $machine,
        AppInterface $app,
        CompanyInterface $company,
        AgentDeployment $deployment,
    ): void {
        LaunchAgentJob::dispatch(
            $agent,
            $machine,
            $app,
            $company,
            $deployment,
        );
    }
}
