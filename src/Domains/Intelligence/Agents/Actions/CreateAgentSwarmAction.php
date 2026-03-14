<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\DataTransferObject\AgentSwarm as AgentSwarmData;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentSwarm;
use Kanvas\Intelligence\Agents\Models\AgentSwarmMember;

class CreateAgentSwarmAction
{
    public function __construct(
        protected readonly AgentSwarmData $data,
    ) {
    }

    public function execute(): AgentSwarm
    {
        $slug = Str::slug($this->data->name);
        $exists = AgentSwarm::where('slug', $slug)
            ->fromApp($this->data->app)
            ->fromCompany($this->data->company)
            ->notDeleted()
            ->exists();

        if ($exists) {
            throw new ValidationException("An agent swarm with the name '{$this->data->name}' already exists");
        }

        return DB::connection('intelligence')->transaction(function () {
            $swarm = new AgentSwarm();
            $swarm->apps_id = $this->data->app->getId();
            $swarm->companies_id = $this->data->company->getId();
            $swarm->users_id = $this->data->user->getId();
            $swarm->name = $this->data->name;
            $swarm->description = $this->data->description;
            $swarm->status = $this->data->status->value;
            $swarm->config = $this->data->config;
            $swarm->is_active = $this->data->isActive();
            $swarm->saveOrFail();

            if ($this->data->agentIds !== null && count($this->data->agentIds) > 0) {
                $this->syncAgents($swarm, $this->data->agentIds);
            }

            return $swarm;
        });
    }

    /**
     * @param array<int> $agentIds
     */
    protected function syncAgents(AgentSwarm $swarm, array $agentIds): void
    {
        foreach ($agentIds as $agentId) {
            Agent::getByIdFromCompanyApp(
                $agentId,
                $this->data->company,
                $this->data->app
            );

            AgentSwarmMember::create([
                'agent_swarm_id' => $swarm->getId(),
                'agent_id' => $agentId,
            ]);
        }
    }
}
