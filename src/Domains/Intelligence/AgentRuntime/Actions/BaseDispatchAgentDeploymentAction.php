<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Intelligence\AgentRuntime\Contracts\ProviderConfig;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * Abstract base for dispatching an agent deployment to a remote machine.
 *
 * Creates or reuses an AgentDeployment record (setting provider, container_name,
 * system_user, port pair, status = provisioning) then enqueues the concrete
 * provider's LaunchAgentJob for async execution.
 *
 * Concrete subclasses provide getProviderConfig() and dispatchLaunchJob().
 */
abstract class BaseDispatchAgentDeploymentAction
{
    public function __construct(
        protected Agent $agent,
        protected AgentMachine $machine,
        protected AppInterface $app,
        protected CompanyInterface $company,
    ) {
    }

    abstract protected function getProviderConfig(): ProviderConfig;

    abstract protected function dispatchLaunchJob(
        Agent $agent,
        AgentMachine $machine,
        AppInterface $app,
        CompanyInterface $company,
        AgentDeployment $deployment,
    ): void;

    public function execute(): AgentDeployment
    {
        $config     = $this->getProviderConfig();
        $systemUser = 'agent-' . $this->agent->slug;
        $ports      = $this->machine->allocatePortPair();

        $deployment = AgentDeployment::where('agent_machine_id', $this->machine->getId())
            ->where('system_user', $systemUser)
            ->where('is_deleted', 0)
            ->first();

        if ($deployment) {
            $deployment->status        = 'provisioning';
            $deployment->error_message = null;
            $deployment->gateway_port  = $ports['gateway_port'];
            $deployment->proxy_port    = $ports['proxy_port'];
            $deployment->saveOrFail();
        } else {
            $deployment                    = new AgentDeployment();
            $deployment->apps_id           = $this->app->getId();
            $deployment->companies_id      = $this->company->getId();
            $deployment->agent_id          = $this->agent->getId();
            $deployment->agent_machine_id  = $this->machine->getId();
            $deployment->system_user       = $systemUser;
            $deployment->home_directory    = '/home/' . $systemUser;
            $deployment->gateway_port      = $ports['gateway_port'];
            $deployment->proxy_port        = $ports['proxy_port'];
            $deployment->container_name    = $config->containerPrefix . $this->agent->slug;
            $deployment->provider          = $config->providerName;
            $deployment->status            = 'provisioning';
            $deployment->saveOrFail();
        }

        $this->dispatchLaunchJob(
            $this->agent,
            $this->machine,
            $this->app,
            $this->company,
            $deployment,
        );

        return $deployment;
    }
}
