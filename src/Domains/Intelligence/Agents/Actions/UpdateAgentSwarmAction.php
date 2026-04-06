<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Intelligence\Agents\DataTransferObject\AgentSwarm as AgentSwarmData;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentSwarm;
use Kanvas\Intelligence\Agents\Models\AgentSwarmMember;

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
            $this->swarm->is_active = $this->data->isActive();
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
        AgentSwarmMember::where('agent_swarm_id', $this->swarm->getId())
            ->update(['is_deleted' => 1]);

        foreach ($agentIds as $agentId) {
            Agent::getByIdFromCompanyApp(
                $agentId,
                $this->data->company,
                $this->data->app
            );

            /** @var AgentSwarmMember|null $existing */
            $existing = AgentSwarmMember::where('agent_swarm_id', $this->swarm->getId())
                ->where('agent_id', $agentId)
                ->first();

            if ($existing) {
                $existing->is_deleted = false;
                $existing->saveOrFail();
            } else {
                AgentSwarmMember::create([
                    'agent_swarm_id' => $this->swarm->getId(),
                    'agent_id' => $agentId,
                ]);
            }
        }
    }
}
