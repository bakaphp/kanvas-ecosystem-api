<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Project\DataTransferObject\ProjectContextBundle;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Project\Models\ProjectMember;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Assembles a project's full situational-awareness bundle — objective, members, open work, recent
 * narrative and structured trail — so an agent reads the whole story before it acts. Reads across
 * ALL of the project's channels for the narrative; the ledger for the structured trail.
 */
class ProjectContextService
{
    private const int RECENT_MESSAGE_CHAR_CAP = 1200;

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
            // The human to escalate to (@mention) when work can't be assigned automatically.
            'owner_handle' => $this->userHandle($project, $project->owner),
        ];
    }

    private function userHandle(Project $project, ?Users $user): ?string
    {
        if ($user === null) {
            return null;
        }

        try {
            $displayname = trim($user->getAppProfile($project->app)->displayname);
        } catch (Throwable) {
            return null;
        }

        return $displayname !== '' ? '@' . $displayname : null;
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
                // The exact @handle the mention parser resolves (app displayname). Null when the
                // member has no resolvable handle — the PM can't notify them, so don't offer one.
                'handle' => $this->memberHandle($project, $member),
                // Every member is a Kanvas user — this is the id assign_plan/assign_task need to hand
                // work to a HUMAN. Without it the PM can only ever assign to agents (agent_id below).
                'users_id' => $member->users_id,
                'agent_id' => $member->agent_id,
                // What this agent is for — so the PM assigns by fit, not by name.
                'description' => $member->agent?->description ?? $member->agent?->type?->description,
                // Only agents that can actually own a plan / move the board. Never assign executable
                // work (assign_plan/assign_task) to a member where this is false — they'll stall or fail.
                'can_execute' => (bool) $member->agent?->canExecuteBoardWork(),
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
     * The member's mentionable handle as `@displayname` — the same app-scoped value
     * ParseMessageMentionsAction resolves. Null when it can't be resolved (no app profile / blank).
     */
    private function memberHandle(Project $project, ProjectMember $member): ?string
    {
        return $this->userHandle($project, $member->user);
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
            ->reject(fn (array $row): bool => $row['content'] === '')
            ->values()
            ->all();
    }

    private function messageContent(Message $message): string
    {
        $raw = $message->contentText();

        // Never feed an agent wake PROMPT back in as "context" — that's the growth loop.
        if (str_starts_with(ltrim($raw), '[NS:')) {
            return '';
        }

        return Str::limit($raw, self::RECENT_MESSAGE_CHAR_CAP);
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
