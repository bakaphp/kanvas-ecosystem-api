<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Illuminate\Support\Facades\Storage;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentConfigBackup;
use Kanvas\NervousSystem\Capability\Models\AgentSkill;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Throwable;

class AgentConfigBackupService
{
    public function buildBackupFolder(Apps $app, Agent $agent): string
    {
        return sprintf(
            'config-backups/%s/%s/%s',
            $app->uuid,
            $agent->uuid,
            now()->format('Y-m-d_H-i-s')
        );
    }

    /**
     * Serialize the agent's config, tools, skills, and attached files into an array.
     * Files are physically copied to $backupFolder/files/ on S3.
     */
    public function serialize(Agent $agent, string $backupFolder): array
    {
        $files = $this->serializeFiles($agent, $backupFolder);
        $tools = $this->serializeSelectedTools($agent);
        $agentSkills = $this->serializeAgentSkills($agent);
        $agentOwnedTools = $this->serializeAgentOwnedTools($agent);

        return [
            'version' => '1.1',
            'backed_up_at' => now()->toIso8601String(),
            'agent' => [
                'uuid' => $agent->uuid,
                'name' => $agent->name,
                'description' => $agent->description,
                'soul' => $agent->soul,
                'instructions' => $agent->instructions,
                'output_format' => $agent->output_format,
                'identity' => $agent->identity,
                'role' => $agent->role,
                'config' => $agent->config,
                'user_context' => $agent->user_context,
                'tools_config' => $agent->tools_config,
                'is_active' => $agent->is_active,
                'is_sub_agent' => $agent->is_sub_agent,
                'agent_type' => $agent->type ? ['id' => $agent->type->id, 'name' => $agent->type->name] : null,
                'agent_model' => $agent->model ? ['id' => $agent->model->id, 'name' => $agent->model->name] : null,
                'selected_tools' => $tools,
                'files' => $files,
                'agent_skills' => $agentSkills,
                'agent_owned_tools' => $agentOwnedTools,
            ],
        ];
    }

    /**
     * Upload the serialized backup manifest to S3 and return the stored file path.
     */
    public function upload(array $data, string $backupFolder): string
    {
        $manifestPath = "{$backupFolder}/manifest.json";
        Storage::put($manifestPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $manifestPath;
    }

    /**
     * Download and decode a backup manifest from S3.
     */
    public function download(AgentConfigBackup $backup): array
    {
        $content = Storage::get($backup->file_path);

        return json_decode($content, true);
    }

    private function serializeFiles(Agent $agent, string $backupFolder): array
    {
        return $agent->getFiles()->map(function ($entity) use ($backupFolder) {
            $fs = $entity->filesystem;
            $backupPath = null;

            if ($fs->path) {
                $ext = pathinfo($fs->name, PATHINFO_EXTENSION);
                $destName = $fs->uuid . ($ext ? '.' . $ext : '');
                $destPath = "{$backupFolder}/files/{$destName}";

                try {
                    Storage::copy($fs->path, $destPath);
                    $backupPath = $destPath;
                } catch (Throwable $e) {
                    report($e);
                }
            }

            return [
                'uuid' => $fs->uuid,
                'name' => $fs->name,
                'url' => $fs->url,
                'size' => $fs->size,
                'field_name' => $entity->field_name,
                'backup_path' => $backupPath,
            ];
        })->values()->all();
    }

    private function serializeSelectedTools(Agent $agent): array
    {
        return $agent->selectedTools()->get()->map(fn ($tool) => [
            'id' => $tool->id,
            'uuid' => $tool->uuid,
            'name' => $tool->name,
        ])->values()->all();
    }

    private function serializeAgentSkills(Agent $agent): array
    {
        return AgentSkill::where('agent_id', $agent->id)
            ->where('is_deleted', 0)
            ->with('skill')
            ->get()
            ->map(fn ($agentSkill) => [
                'grant_uuid' => $agentSkill->uuid,
                'is_active' => $agentSkill->is_active,
                'config' => $agentSkill->config,
                'skill' => $agentSkill->skill ? [
                    'uuid' => $agentSkill->skill->uuid,
                    'name' => $agentSkill->skill->name,
                    'description' => $agentSkill->skill->description,
                    'skill_type' => $agentSkill->skill->skill_type,
                    'handler' => $agentSkill->skill->handler,
                    'definition' => $agentSkill->skill->definition,
                    'frameworks' => $agentSkill->skill->frameworks,
                    'version' => $agentSkill->skill->version,
                ] : null,
            ])
            ->values()
            ->all();
    }

    private function serializeAgentOwnedTools(Agent $agent): array
    {
        return Tool::where('agents_id', $agent->id)
            ->where('is_deleted', 0)
            ->get()
            ->map(fn ($tool) => [
                'uuid' => $tool->uuid,
                'name' => $tool->name,
                'description' => $tool->description,
                'tool_type' => $tool->tool_type,
                'handler' => $tool->handler,
                'input_schema' => $tool->input_schema,
                'output_schema' => $tool->output_schema,
                'frameworks' => $tool->frameworks,
                'version' => $tool->version,
                'is_active' => $tool->is_active,
            ])
            ->values()
            ->all();
    }
}
