<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentConfigBackup;
use Kanvas\Intelligence\Agents\Services\AgentConfigBackupService;
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

        // Restore selected tools by slug/id
        if (! empty($agentData['selected_tools'])) {
            $toolIds = Tool::whereIn('id', array_column($agentData['selected_tools'], 'id'))
                ->pluck('id')
                ->all();

            if (! empty($toolIds)) {
                $agent->selectedTools()->sync($toolIds);
            }
        }

        return $agent->fresh();
    }
}
