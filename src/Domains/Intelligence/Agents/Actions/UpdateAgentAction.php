<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Kanvas\Intelligence\Agents\DataTransferObject\Agent;
use Kanvas\Intelligence\Agents\Models\Agent as AgentModel;

class UpdateAgentAction
{
    public function __construct(
        public Agent $agent,
        public AgentModel $agentModel
    ) {
    }

    public function execute(): AgentModel
    {
        $this->agentModel->update([
            'agent_type_id' => $this->agent->agentType->id,
            'name' => $this->agent->name,
            'role' => $this->agent->role,
            'is_active' => $this->agent->is_active,
            'description' => $this->agent->description,
            'config' => $this->agent->config,
            'agent_model_id' => $this->agent->agentModel->id,
            'company_task_list_id' => $this->agent->task?->id ?? null,
        ]);

        return $this->agentModel;
    }
}
