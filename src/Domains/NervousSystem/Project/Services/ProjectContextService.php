<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Services;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Project\DataTransferObject\ProjectContextBundle;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Project\Models\ProjectMember;
use Kanvas\Social\Messages\Models\Message;

/**
 * Assembles a project's full situational-awareness bundle — objective, members, open work, recent
 * narrative and structured trail — so an agent reads the whole story before it acts. Reads across
 * ALL of the project's channels for the narrative; the ledger for the structured trail.
 */
class ProjectContextService
{
    public function buildContextBundle(Project $project, int $historyLimit = 50): ProjectContextBundle
    {
        return new ProjectContextBundle(
            project: $this->projectSummary($project),
            members: $this->members($project),
            plans: $this->plans($project),
            recentMessages: $this->recentMessages($project, $historyLimit),
            recentEvents: $this->recentEvents($project, $historyLimit),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function projectSummary(Project $project): array
    {
        return [
            'id' => $project->getId(),
            'title' => $project->title,
            'objective' => $project->objective,
            'description' => $project->description,
            'status' => $project->status,
            'priority' => $project->priority,
            'completion_pct' => $project->completion_pct,
            'deadline_at' => $project->deadline_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function members(Project $project): array
    {
        return $project->members()
            ->with(['user', 'agent'])
            ->get()
            ->toBase()
            ->map(fn (ProjectMember $member): array => [
                'type' => $member->member_type,
                'role' => $member->role,
                'name' => $this->memberName($member),
                'agent_id' => $member->agent_id,
            ])
            ->all();
    }

    private function memberName(ProjectMember $member): string
    {
        $user = $member->user;
        if ($user === null) {
            return 'Unknown';
        }

        $full = trim((string) $user->firstname . ' ' . (string) $user->lastname);

        return $full !== '' ? $full : (string) $user->displayname;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function plans(Project $project): array
    {
        return $project->plans()
            ->open()
            ->with('tasks')
            ->orderByDesc('priority')
            ->get()
            ->toBase()
            ->map(fn (Plan $plan): array => [
                'plan_id' => $plan->getId(),
                'title' => $plan->title,
                'status' => $plan->status,
                'completion_pct' => $plan->completion_pct,
                'tasks' => $plan->tasks
                    ->toBase()
                    ->map(fn (Task $task): array => [
                        'task_id' => $task->getId(),
                        'title' => $task->title,
                        'status' => $task->status,
                        'agent_id' => $task->agent_id,
                    ])
                    ->all(),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentMessages(Project $project, int $limit): array
    {
        $channelIds = $project->channels()->pluck('id')->all();
        if ($channelIds === []) {
            return [];
        }

        return Message::query()
            ->whereHas('channels', fn (Builder $query) => $query->whereIn('channels.id', $channelIds))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->toBase()
            ->map(fn (Message $message): array => [
                'type' => $message->messageType?->name,
                'content' => $this->messageContent($message),
                'at' => $message->created_at?->toIso8601String(),
            ])
            ->all();
    }

    private function messageContent(Message $message): string
    {
        $payload = $message->message;
        if (! is_array($payload)) {
            return is_scalar($payload) ? (string) $payload : '';
        }

        foreach (['content', 'text', 'message', 'body'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key])) {
                return $payload[$key];
            }
        }

        return '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentEvents(Project $project, int $limit): array
    {
        return Event::query()
            ->where('source_entity_type', Project::class)
            ->where('source_entity_id', $project->getId())
            ->recent()
            ->limit($limit)
            ->get()
            ->toBase()
            ->map(fn (Event $event): array => [
                'event_type' => $event->event_type,
                'status' => $event->status,
                'at' => $event->occurred_at->toIso8601String(),
            ])
            ->all();
    }
}
