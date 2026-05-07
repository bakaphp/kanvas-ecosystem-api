<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Kanvas\Intelligence\Agents\DataTransferObject\AgentType;
use Kanvas\Intelligence\Agents\Models\AgentType as AgentTypeModel;

class UpdateAgentTypeAction
{
    public function __construct(
        public AgentType $agentType,
        public AgentTypeModel $agentTypeModel
    ) {
    }

    public function execute(): AgentTypeModel
    {
        $this->agentTypeModel->update([
            'name' => $this->agentType->name,
            'description' => $this->agentType->description,
            'config' => $this->agentType->config,
            'role' => $this->agentType->role,
            'is_active' => $this->agentType->is_active,
            'is_published' => $this->agentType->is_published,
            'is_multi_agent' => $this->agentType->is_multi_agent,
            'multi_agent_list' => $this->agentType->multi_agent_list ?? [],
        ]);

        return $this->agentTypeModel;
    }
}
