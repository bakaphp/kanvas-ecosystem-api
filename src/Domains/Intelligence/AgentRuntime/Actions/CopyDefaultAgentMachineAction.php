<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Intelligence\Agents\Actions\CreateAgentMachineAction;
use Kanvas\Intelligence\Agents\DataTransferObject\AgentMachine as AgentMachineData;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

class CopyDefaultAgentMachineAction
{
    public function __construct(
        protected readonly AgentMachine $source,
        protected readonly CompanyInterface $company,
        protected readonly AppInterface $app,
    ) {
    }

    public function execute(): AgentMachine
    {
        return new CreateAgentMachineAction(AgentMachineData::from([
            'app' => $this->app,
            'company' => $this->company,
            'name' => $this->source->name . ' - Company ' . $this->company->getId(),
            'host' => $this->source->host,
            'ssh_user' => $this->source->ssh_user,
            'ssh_private_key' => $this->source->ssh_private_key,
            'ssh_port' => $this->source->ssh_port,
            'region' => $this->source->region,
            'port_range_start' => $this->source->port_range_start,
            'port_range_end' => $this->source->port_range_end,
            'max_agents' => $this->source->max_agents,
            'is_active' => $this->source->is_active,
        ]))->execute();
    }
}
