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

            $this->data->applyTo($machine);
            $machine->saveOrFail();

            return $machine;
        });
    }
}
