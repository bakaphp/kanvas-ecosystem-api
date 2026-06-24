<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Illuminate\Support\Facades\Storage;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentConfigBackup;

class AgentConfigBackupService
{
    /**
     * Serialize the agent's config, tools, and attached files into an array.
     */
    public function serialize(Agent $agent): array
    {
        $files = $agent->getFiles()->map(fn ($entity) => [
            'uuid' => $entity->filesystem->uuid,
            'name' => $entity->filesystem->name,
            'url' => $entity->filesystem->url,
            'size' => $entity->filesystem->size,
            'field_name' => $entity->field_name,
        ])->values()->all();

        $tools = $agent->selectedTools()->get()->map(fn ($tool) => [
            'id' => $tool->id,
            'uuid' => $tool->uuid,
            'slug' => $tool->slug ?? $tool->name,
            'name' => $tool->name,
        ])->values()->all();

        return [
            'version' => '1.0',
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
            ],
        ];
    }

    /**
     * Upload the serialized backup to S3 and return the stored file path.
     */
    public function upload(Agent $agent, array $data): string
    {
        $app = $agent->app ?? app(\Kanvas\Apps\Models\Apps::class);
        $timestamp = now()->format('Y-m-d_H-i-s');
        $path = "config-backups/{$app->uuid}/{$agent->uuid}/{$timestamp}.json";

        Storage::put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $path;
    }

    /**
     * Download and decode a backup payload from S3.
     */
    public function download(AgentConfigBackup $backup): array
    {
        $content = Storage::get($backup->file_path);

        return json_decode($content, true);
    }
}
