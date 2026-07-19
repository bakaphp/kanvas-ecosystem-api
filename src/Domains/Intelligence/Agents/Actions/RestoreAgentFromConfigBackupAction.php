<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentConfigBackup;
use Kanvas\Intelligence\Agents\Services\AgentConfigBackupService;
use Kanvas\NervousSystem\Capability\Models\AgentSkill;
use Kanvas\NervousSystem\Capability\Models\Skill;
use Kanvas\NervousSystem\Capability\Models\Tool;

class RestoreAgentFromConfigBackupAction
{
    public function __construct(
        protected AgentConfigBackup $backup,
    ) {
    }

    public function execute(): Agent
    {
        if ($this->backup->status !== 'completed' || $this->backup->file_path === null) {
            throw new ModelNotFoundException('Backup is not in a completed state.');
        }

        $service = new AgentConfigBackupService();
        $data = $service->download($this->backup);
        $agentData = $data['agent'];

        $agent = Agent::findOrFail($this->backup->agents_id);

        $agent->update([
            'name' => $agentData['name'],
            'description' => $agentData['description'],
            'soul' => $agentData['soul'],
            'instructions' => $agentData['instructions'],
            'output_format' => $agentData['output_format'],
            'identity' => $agentData['identity'],
            'role' => $agentData['role'],
            'config' => $agentData['config'],
            'user_context' => $agentData['user_context'],
            'tools_config' => $agentData['tools_config'],
            'is_active' => $agentData['is_active'],
        ]);

        if (! empty($agentData['selected_tools'])) {
            $toolIds = Tool::whereIn('id', array_column($agentData['selected_tools'], 'id'))
                ->pluck('id')
                ->all();

            if (! empty($toolIds)) {
                $agent->selectedTools()->sync($toolIds);
            }
        }

        if (! empty($agentData['agent_skills'])) {
            $this->restoreAgentSkills($agent, $agentData['agent_skills']);
        }

        if (! empty($agentData['agent_owned_tools'])) {
            $this->restoreAgentOwnedTools($agent, $agentData['agent_owned_tools']);
        }

        return $agent->fresh();
    }

    private function restoreAgentSkills(Agent $agent, array $agentSkillsData): void
    {
        foreach ($agentSkillsData as $skillData) {
            if (empty($skillData['skill'])) {
                continue;
            }

            $skill = Skill::where('uuid', $skillData['skill']['uuid'])->first();

            if ($skill === null) {
                continue;
            }

            AgentSkill::updateOrCreate(
                ['agent_id' => $agent->id, 'skill_id' => $skill->id],
                [
                    'apps_id' => $agent->apps_id,
                    'companies_id' => $agent->companies_id,
                    'is_active' => $skillData['is_active'] ?? true,
                    'config' => $skillData['config'] ?? null,
                    'granted_at' => now(),
                    'is_deleted' => 0,
                ]
            );
        }
    }

    private function restoreAgentOwnedTools(Agent $agent, array $toolsData): void
    {
        foreach ($toolsData as $toolData) {
            Tool::updateOrCreate(
                ['uuid' => $toolData['uuid']],
                [
                    'apps_id' => $agent->apps_id,
                    'agents_id' => $agent->id,
                    'name' => $toolData['name'],
                    'description' => $toolData['description'] ?? null,
                    'tool_type' => $toolData['tool_type'],
                    'handler' => $toolData['handler'] ?? null,
                    'input_schema' => $toolData['input_schema'] ?? null,
                    'output_schema' => $toolData['output_schema'] ?? null,
                    'frameworks' => $toolData['frameworks'] ?? [],
                    'version' => $toolData['version'] ?? '1.0',
                    'is_active' => $toolData['is_active'] ?? true,
                    'is_deleted' => 0,
                ]
            );
        }
    }
}
