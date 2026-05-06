<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\AgentRuntime\Actions\BaseDispatchAgentDeploymentAction;
use Kanvas\Connectors\AgentRuntime\Contracts\ProviderConfig;
use Kanvas\Connectors\OpenClaw\Jobs\LaunchAgentJob;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * OpenClaw-specific dispatch — thin subclass that wires the provider config and launch job.
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
