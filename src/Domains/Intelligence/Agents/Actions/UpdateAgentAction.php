<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\DataTransferObject\Agent;
use Kanvas\Intelligence\Agents\Models\Agent as AgentModel;
use Kanvas\NervousSystem\Capability\Models\Tool;

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
            'user_id' => $this->agent->user->id,
            'name' => $this->agent->name,
            'role' => $this->agent->role,
            'is_active' => $this->agent->is_active,
            'description' => $this->agent->description,
            'config' => $this->agent->config,
            'agent_model_id' => $this->agent->agentModel?->id,
            'company_task_list_id' => $this->agent->task?->id ?? null,
            'soul' => $this->agent->soul,
            'instructions' => $this->agent->instructions,
            'output_format' => $this->agent->outputFormat,
            'identity' => $this->agent->identity,
            'user_context' => $this->agent->userContext,
            'tools_config' => $this->agent->toolsConfig,
            'parent_id' => $this->agent->parentAgent?->getId(),
        ]);
        $this->agentModel->communicationChannels()->sync($this->agent->communicationChannel);

        if ($this->agentModel->is_sub_agent) {
            Tool::query()
                ->where('agents_id', $this->agentModel->getId())
                ->first()
                ?->update([
                    'name' => Str::slug($this->agent->name),
                    'description' => $this->agent->soul ?? $this->agent->description ?? $this->agent->name,
                ]);
        }

        return $this->agentModel;
    }
}
