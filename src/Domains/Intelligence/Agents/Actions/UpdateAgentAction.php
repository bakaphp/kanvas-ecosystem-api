<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\DataTransferObject\Agent;
use Kanvas\Intelligence\Agents\Models\Agent as AgentModel;
use Kanvas\NervousSystem\Capability\Actions\CreateToolAction;
use Kanvas\NervousSystem\Capability\DataTransferObject\Tool as ToolData;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
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
        // Names the human behind the edit so the prompt-history row records who changed it rather
        // than falling back to the agent's own user. The observer reads this during the update.
        $this->agentModel->versionEditedByUserId = $this->agent->createdBy?->getId();

        $this->agentModel->update([
            'agent_type_id' => $this->agent->agentType->id,
            'user_id' => $this->agent->user->id,
            'name' => $this->agent->name,
            'role' => $this->agent->role,
            'is_active' => $this->agent->is_active,
            'description' => $this->agent->description,
            'config' => $this->agent->config,
            'agent_model_id' => $this->agent->agentModel?->id,
            'agent_llm_config_id' => $this->agent->agentLlmConfig?->getId(),
            'company_task_list_id' => $this->agent->task?->id ?? null,
            'soul' => $this->agent->soul,
            'instructions' => $this->agent->instructions,
            'output_format' => $this->agent->outputFormat,
            'identity' => $this->agent->identity,
            'user_context' => $this->agent->userContext,
            'tools_config' => $this->agent->toolsConfig,
            'voice_config' => $this->agent->voiceConfig,
            'parent_id' => $this->agent->parentAgent?->getId(),
            'is_sub_agent' => $this->agent->isSubAgent,
        ]);
        $this->agentModel->communicationChannels()->sync($this->agent->communicationChannel);

        if ($this->agentModel->is_sub_agent) {
            $this->syncSubAgentTool();
        }

        return $this->agentModel;
    }

    private function syncSubAgentTool(): void
    {
        $existing = Tool::query()
            ->where('agents_id', $this->agentModel->getId())
            ->first();

        $name = Str::slug($this->agent->name);
        $description = $this->agent->soul ?? $this->agent->description ?? $this->agent->name;

        if ($existing !== null) {
            $existing->update([
                'name' => $name,
                'description' => $description,
            ]);

            return;
        }

        $framework = $this->agent->agentType->provider ?? 'laravel';

        $tool = new CreateToolAction(new ToolData(
            app: $this->agent->app,
            name: $name,
            description: $description,
            frameworks: [$framework],
            toolType: ToolTypeEnum::SUB_AGENT,
        ))->execute();

        $tool->update(['agents_id' => $this->agentModel->getId()]);
    }
}
