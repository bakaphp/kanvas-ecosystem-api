<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Baka\Support\Str;
use Kanvas\Intelligence\Agents\DataTransferObject\AgentType;
use Kanvas\Intelligence\Agents\Models\AgentType as AgentTypeModel;

class CreateAgentTypeAction
{
    public function __construct(
        public AgentType $agentType
    ) {
    }

    public function execute(): AgentTypeModel
    {
        return AgentTypeModel::updateOrCreate([
            'apps_id' => $this->agentType->app->id,
            'name' => $this->agentType->name,
            'description' => $this->agentType->description,
            'config' => $this->agentType->config,
            'role' => $this->agentType->role,
            'is_active' => $this->agentType->is_active,
            'is_published' => $this->agentType->is_published,
            'is_multi_agent' => $this->agentType->is_multi_agent,
            'multi_agent_list' => $this->agentType->multi_agent_list ?? [],
        ]);
    }
}
