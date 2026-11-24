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
            $this->data->applyTo($this->machine);
            $this->machine->saveOrFail();

            return $this->machine;
        });
    }
}
