<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\OpenClaw\Enums\DeploymentStatusEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * Create an AgentDeployment record with allocated ports and naming conventions.
 *
 * Naming: system_user = "agent-{slug}", container = "openclaw-{slug}", home = "/home/agent-{slug}"
 * Ports are allocated from the machine's available range via AgentMachine::allocatePortPair().
 * The deployment starts in PROVISIONING status — LaunchAgentOnMachineAction handles the rest.
 */
class CreateAgentDeploymentAction
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
        $ports = $this->machine->allocatePortPair();
        $slug = $this->agent->slug;
        $systemUser = 'agent-' . $slug;

        $deployment = new AgentDeployment();
        $deployment->apps_id = $this->app->getId();
        $deployment->companies_id = $this->company->getId();
        $deployment->agent_id = $this->agent->getId();
        $deployment->agent_machine_id = $this->machine->getId();
        $deployment->system_user = $systemUser;
        $deployment->home_directory = '/home/' . $systemUser;
        $deployment->gateway_port = $ports['gateway_port'];
        $deployment->proxy_port = $ports['proxy_port'];
        $deployment->container_name = 'openclaw-' . $slug;
        $deployment->status = DeploymentStatusEnum::PROVISIONING->value;
        $deployment->saveOrFail();

        return $deployment;
    }
}
