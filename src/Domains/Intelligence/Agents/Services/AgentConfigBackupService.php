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
use ZipArchive;

class AgentConfigBackupService
{
    /**
     * Serialize the agent's config, tools, skills, and file metadata into an array.
     * Each file records its original S3 path so upload() can pull and zip the content.
     */
    public function serialize(Agent $agent): array
    {
        $files = $this->serializeFiles($agent);
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
     * Build a ZIP containing manifest.json + all agent files, then upload to S3.
     * Returns the S3 path of the uploaded ZIP.
     */
    public function upload(Agent $agent, Apps $app, array $data): string
    {
        $timestamp = now()->format('Y-m-d_H-i-s');
        $s3Path = "config-backups/{$app->uuid}/{$agent->uuid}/{$timestamp}.zip";

        $tempPath = tempnam(sys_get_temp_dir(), 'agent_backup_') . '.zip';

        try {
            $zip = new ZipArchive();
            $zip->open($tempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

            $zip->addFromString('manifest.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            foreach ($data['agent']['files'] as $file) {
                if (empty($file['source_path'])) {
                    continue;
                }

                try {
                    $content = Storage::get($file['source_path']);

                    if ($content !== null) {
                        $zip->addFromString($file['zip_path'], $content);
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            }

            $zip->close();

            Storage::put($s3Path, file_get_contents($tempPath));
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }

        return $s3Path;
    }

    /**
     * Download the ZIP from S3, extract manifest.json, and return the decoded payload.
     */
    public function download(AgentConfigBackup $backup): array
    {
        $zipContent = Storage::get($backup->file_path);

        $tempPath = tempnam(sys_get_temp_dir(), 'agent_restore_') . '.zip';

        try {
            file_put_contents($tempPath, $zipContent);

            $zip = new ZipArchive();
            $zip->open($tempPath);
            $manifest = $zip->getFromName('manifest.json');
            $zip->close();
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }

        return json_decode($manifest, true);
    }

    private function serializeFiles(Agent $agent): array
    {
        return $agent->getFiles()->map(function ($entity) {
            $fs = $entity->filesystem;
            $ext = pathinfo($fs->name, PATHINFO_EXTENSION);
            $zipPath = 'files/' . $fs->uuid . ($ext ? '.' . $ext : '');

            return [
                'uuid' => $fs->uuid,
                'name' => $fs->name,
                'url' => $fs->url,
                'size' => $fs->size,
                'field_name' => $entity->field_name,
                'source_path' => $fs->path,
                'zip_path' => $zipPath,
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
