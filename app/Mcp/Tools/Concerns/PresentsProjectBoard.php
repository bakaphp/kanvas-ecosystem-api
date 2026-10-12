<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Project\Support\ProjectBoardColumns;

trait PresentsProjectBoard
{
    /**
     * @return array<string, mixed>
     */
    protected function presentProject(Project $project): array
    {
        return [
            'id' => $project->getId(),
            'uuid' => $project->uuid,
            'title' => $project->title,
            'slug' => $project->slug,
            'objective' => $project->objective,
            'description' => $project->description,
            'status' => $project->status,
            'priority' => $project->priority,
            'completion_pct' => $project->completion_pct,
            'deadline_at' => $project->deadline_at?->toIso8601String(),
            'agent_id' => $project->agent_id,
            'workspace_id' => $project->workspace_id,
            'created_at' => $project->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentTask(Plan $plan): array
    {
        return [
            'id' => $plan->getId(),
            'uuid' => $plan->uuid,
            'project_id' => $plan->project_id,
            'title' => $plan->title,
            'description' => $plan->description,
            'status' => $plan->status,
            'board_column_key' => $plan->board_column_key,
            'priority' => $plan->priority,
            'completion_pct' => $plan->completion_pct,
            'deadline_at' => $plan->deadline_at?->toIso8601String(),
            'agent_id' => $plan->agent_id,
            'assigned_users_id' => $plan->assigned_users_id,
            'created_at' => $plan->created_at?->toIso8601String(),
        ];
    }

    /**
     * Only a board drag writes board_column_key, so a plan created anywhere else carries none and the
     * board renders it in the column covering its status. Reads must place it the same way or a
     * freshly created task would be missing from its own column.
     */
    protected function columnKeyOf(Project $project, Plan $plan): ?string
    {
        return $plan->board_column_key
            ?? new ProjectBoardColumns()->keyForStatus($project, $plan->status);
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentSubtask(Task $task): array
    {
        return [
            'id' => $task->getId(),
            'uuid' => $task->uuid,
            'task_id' => $task->plan_id,
            'sequence' => $task->sequence,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status,
            'blocked_reason' => $task->blocked_reason,
            'agent_id' => $task->agent_id,
            'completed_at' => $task->completed_at?->toIso8601String(),
        ];
    }

    /**
     * Carries the URL, unlike the agent-side PresentsEntityFiles listing: an MCP client has no
     * read_file tool to resolve a filesystem id with, so the link is the only usable handle.
     *
     * @return list<array<string, mixed>>
     */
    protected function presentFiles(EloquentModel $entity): array
    {
        return $entity->files()
            ->orderBy('filesystem.id')
            ->get()
            ->map(fn (Filesystem $file): array => [
                'filesystem_id' => (int) $file->getId(),
                'file_name' => $file->name,
                'file_type' => $file->file_type,
                'size_bytes' => (int) $file->size,
                'file_url' => $file->url,
            ])
            ->values()
            ->all();
    }
}
