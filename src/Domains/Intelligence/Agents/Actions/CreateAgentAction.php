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

class CreateAgentAction
{
    public function __construct(
        public Agent $agent
    ) {
    }

    public function execute(): AgentModel
    {
        $agent = AgentModel::updateOrCreate([
            'apps_id' => $this->agent->app->id,
            'companies_id' => $this->agent->company->id,
            'user_id' => $this->agent->user->id,
            'created_by_users_id' => $this->agent->createdBy?->id ?? $this->agent->user->id,
            'agent_type_id' => $this->agent->agentType->id,
            'agent_model_id' => $this->agent->agentModel?->id ?? null,
            'name' => $this->agent->name,
            'role' => $this->agent->role,
            'is_active' => $this->agent->is_active,
            'description' => $this->agent->description,
            'config' => $this->agent->config,
            'company_task_list_id' => $this->agent->task?->id ?? null,
            'soul' => $this->agent->soul,
            'instructions' => $this->agent->instructions,
            'output_format' => $this->agent->outputFormat,
            'identity' => $this->agent->identity,
            'user_context' => $this->agent->userContext,
            'tools_config' => $this->agent->toolsConfig,
            'parent_id' => $this->agent->parentAgent?->getId(),
            'is_sub_agent' => $this->agent->isSubAgent,
            'deployment_status' => 'pending',
        ]);

        if ($this->agent->communicationChannel) {
            $agent->communicationChannels()->sync($this->agent->communicationChannel);
        }

        if ($this->agent->isSubAgent) {
            $this->ensureSubAgentTool($agent);
        }

        return $agent;
    }

    private function ensureSubAgentTool(AgentModel $agent): void
    {
        $exists = Tool::query()
            ->where('agents_id', $agent->getId())
            ->exists();

        if ($exists) {
            return;
        }

        $framework = $this->agent->agentType->provider ?? 'laravel';

        $tool = new CreateToolAction(new ToolData(
            app: $this->agent->app,
            name: Str::slug($agent->name),
            description: $agent->soul ?? $agent->description ?? $agent->name,
            frameworks: [$framework],
            toolType: ToolTypeEnum::SUB_AGENT,
        ))->execute();

        $tool->update(['agents_id' => $agent->getId()]);
    }
}
