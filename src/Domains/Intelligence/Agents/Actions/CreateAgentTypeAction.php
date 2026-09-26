<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

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
        return AgentTypeModel::updateOrCreate(
            [
                'apps_id' => $this->agentType->app->id,
                'name' => $this->agentType->name,
            ],
            [
                'description' => $this->agentType->description,
                'provider' => $this->agentType->provider,
                'handler' => $this->agentType->handler,
                'config' => $this->agentType->config,
                'role' => $this->agentType->role ?? '',
                'soul' => $this->agentType->soul,
                'instructions' => $this->agentType->instructions,
                'output_format' => $this->agentType->output_format,
                'is_active' => $this->agentType->is_active,
                'is_published' => $this->agentType->is_published,
                'is_multi_agent' => $this->agentType->is_multi_agent,
                'is_default' => $this->agentType->is_default,
                'weight' => $this->agentType->weight,
                'multi_agent_list' => $this->agentType->multi_agent_list ?? [],
            ]
        );
    }
}
