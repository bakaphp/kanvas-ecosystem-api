<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\OpenClaw\Jobs\LaunchAgentJob;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

class DispatchAgentDeploymentAction
{
    public function __construct(
        protected Agent $agent,
        protected AgentMachine $machine,
        protected AppInterface $app,
        protected CompanyInterface $company,
    ) {
    }

    public function execute(): AgentDeployment
    {
        $systemUser = 'agent-' . $this->agent->slug;

        $deployment = AgentDeployment::where('agent_machine_id', $this->machine->getId())
            ->where('system_user', $systemUser)
            ->where('is_deleted', 0)
            ->first();

        if ($deployment) {
            $deployment->status = 'provisioning';
            $deployment->error_message = null;
            $deployment->gateway_port = 0;
            $deployment->proxy_port = 0;
            $deployment->saveOrFail();
        } else {
            $deployment = new AgentDeployment();
            $deployment->apps_id = $this->app->getId();
            $deployment->companies_id = $this->company->getId();
            $deployment->agent_id = $this->agent->getId();
            $deployment->agent_machine_id = $this->machine->getId();
            $deployment->system_user = $systemUser;
            $deployment->home_directory = '/home/' . $systemUser;
            $deployment->gateway_port = 0;
            $deployment->proxy_port = 0;
            $deployment->container_name = 'openclaw-' . $this->agent->slug;
            $deployment->status = 'provisioning';
            $deployment->saveOrFail();
        }

        LaunchAgentJob::dispatch(
            $this->agent,
            $this->machine,
            $this->app,
            $this->company,
            $deployment,
        );

        return $deployment;
    }
}
