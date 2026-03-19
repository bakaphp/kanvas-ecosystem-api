<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Intelligence\Agents\DataTransferObject\AgentMachine as AgentMachineData;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

class CreateAgentMachineAction
{
    public function __construct(
        protected readonly AgentMachineData $data,
    ) {
    }

    public function execute(): AgentMachine
    {
        return DB::connection('intelligence')->transaction(function (): AgentMachine {
            /** @var AgentMachine $machine */
            $machine = AgentMachine::firstOrNew([
                'apps_id' => $this->data->app->getId(),
                'companies_id' => $this->data->company->getId(),
                'host' => $this->data->host,
                'is_deleted' => 0,
            ]);

            $machine->name = $this->data->name;
            $machine->ssh_port = $this->data->ssh_port;
            $machine->ssh_user = $this->data->ssh_user;
            $machine->ssh_private_key = $this->data->ssh_private_key;
            $machine->region = $this->data->region;
            $machine->port_range_start = $this->data->port_range_start;
            $machine->port_range_end = $this->data->port_range_end;
            $machine->max_agents = $this->data->max_agents;
            $machine->is_active = $this->data->is_active;
            $machine->saveOrFail();

            return $machine;
        });
    }
}
