<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Intelligence\Agents\DataTransferObject\AgentSwarm as AgentSwarmData;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentSwarm;

class UpdateAgentSwarmAction
{
    public function __construct(
        protected readonly AgentSwarm $swarm,
        protected readonly AgentSwarmData $data,
    ) {
    }

    public function execute(): AgentSwarm
    {
        return DB::connection('intelligence')->transaction(function () {
            $this->swarm->name = $this->data->name;
            $this->swarm->description = $this->data->description;
            $this->swarm->status = $this->data->status->value;
            $this->swarm->config = $this->data->config;
            $this->swarm->saveOrFail();

            if ($this->data->agentIds !== null) {
                $this->syncAgents($this->data->agentIds);
            }

            return $this->swarm;
        });
    }

    /**
     * @param array<int> $agentIds
     */
    protected function syncAgents(array $agentIds): void
    {
        $this->swarm->agents()->detach();

        foreach ($agentIds as $agentId) {
            Agent::getByIdFromCompanyApp(
                $agentId,
                $this->data->company,
                $this->data->app
            );

            $this->swarm->agents()->attach($agentId);
        }
    }
}
