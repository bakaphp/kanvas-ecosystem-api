<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Intelligence\Agents\DataTransferObject\AgentMachine as AgentMachineData;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

class UpdateAgentMachineAction
{
    public function __construct(
        protected readonly AgentMachine $machine,
        protected readonly AgentMachineData $data,
    ) {
    }

    public function execute(): AgentMachine
    {
        return DB::connection('intelligence')->transaction(function () {
            $this->machine->name = $this->data->name;
            $this->machine->host = $this->data->host;
            $this->machine->ssh_port = $this->data->ssh_port;
            $this->machine->ssh_user = $this->data->ssh_user;
            $this->machine->ssh_private_key = $this->data->ssh_private_key;
            $this->machine->region = $this->data->region;
            $this->machine->port_range_start = $this->data->port_range_start;
            $this->machine->port_range_end = $this->data->port_range_end;
            $this->machine->max_agents = $this->data->max_agents;
            $this->machine->is_active = $this->data->is_active;
            $this->machine->saveOrFail();

            return $this->machine;
        });
    }
}
