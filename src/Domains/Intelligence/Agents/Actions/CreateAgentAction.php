<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Kanvas\Intelligence\Agents\DataTransferObject\Agent;
use Kanvas\Intelligence\Agents\Models\Agent as AgentModel;

class CreateAgentAction
{
    public function __construct(
        public Agent $agent
    ) {
    }

    public function execute(): AgentModel
    {
        return AgentModel::updateOrCreate([
            'apps_id' => $this->agent->app->id,
            'companies_id' => $this->agent->company->id,
            'user_id' => $this->agent->user->id,
            'agent_type_id' => $this->agent->agentType->id,
            'agent_model_id' => $this->agent->agentModel?->id ?? null,
            'name' => $this->agent->name,
            'role' => $this->agent->role,
            'is_active' => $this->agent->is_active,
            'description' => $this->agent->description,
            'config' => $this->agent->config,
            'company_task_list_id' => $this->agent->task?->id ?? null,
        ]);
    }
}
