<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Kanvas\HumanResources\Employees\Services\EmployeeIdentityResolver;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Project\DataTransferObject\ProjectContextBundle;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Project\Models\ProjectMember;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;
use Throwable;

class ProjectContextService
{
    private const int RECENT_MESSAGE_CHAR_CAP = 1200;

    /**
     * A worker's output is capped at 4000 characters per task, so passing every one through whole
     * would cost more context than the rest of the bundle together. Matched to the message cap, which
     * is comfortably above a typical result; the full text stays on the task for anyone who opens it.
     */
    private const int TASK_RESULT_CHAR_CAP = 1200;

    /**
     * How many finished plans stay visible after they close.
     *
     * `open()` alone drops a plan the moment it is done, taking its tasks and their output with it —
     * so the PM loses the work at exactly the point someone asks how it went. Asked for the file its
     * own worker had just produced, it had no plan, no task and no result in context, and answered
     * with a fluent invention instead of "I can't see it".
     */
    private const int RECENTLY_CLOSED_PLANS = 5;

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
        /** @var Collection<int, ProjectMember> $members */
        $members = $project->members()->with(['user', 'agent'])->get();
        $memberDescriptions = $this->memberCapabilityDescriptions($project, $members);

        return $members
            ->toBase()
            ->map(fn (ProjectMember $member): array => [
                'type' => $member->member_type,
                'role' => $member->role,
                'name' => $this->memberName($member),
                'handle' => $this->memberHandle($project, $member),
                'users_id' => $member->users_id,
                'agent_id' => $member->agent_id,
                // An agent's own description is its sharpest routing signal, so it wins for agents;
                // otherwise use the HR org-chart role (which lists agents and humans alike). Humans
                // have no self-description, so for them HR is the source; none in HR → null.
                'description' => $member->agent?->description
                    ?? $memberDescriptions[$member->users_id]
                    ?? $member->agent?->type?->description,
                'can_execute' => (bool) $member->agent?->canExecuteBoardWork(),
            ])
            ->all();
    }

    /**
     * Map each member's users_id to their HR org-chart role — HR lists agents and humans alike, so
     * this covers both. One `hr` query for all members; no N+1 across connections.
     *
     * @param Collection<int, ProjectMember> $members
     *
     * @return array<int, string> keyed by users_id
     */
    private function memberCapabilityDescriptions(Project $project, Collection $members): array
    {
        $userIds = $members
            ->pluck('users_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        $descriptions = [];
        foreach (new EmployeeIdentityResolver()->fromUsers($userIds, $project->company, $project->app) as $employee) {
            $description = $employee->describeForAssignment();
            if ($description !== null) {
                $descriptions[(int) $employee->users_id] = $description;
            }
        }

        return $descriptions;
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
        $open = $project->plans()
            ->open()
            ->with('tasks')
            ->orderByDesc('priority')
            ->get();

        return $open->concat($this->recentlyClosedPlans($project, $open))
            ->toBase()
            ->map(fn (Plan $plan): array => [
                'plan_id' => $plan->getId(),
                'title' => $plan->title,
                'status' => $plan->status,
                'completion_pct' => $plan->completion_pct,
                'tasks' => $plan->tasks
                    ->toBase()
                    ->map(fn (Task $task): array => array_filter(
                        [
                            'task_id' => $task->getId(),
                            'title' => $task->title,
                            'status' => $task->status,
                            'agent_id' => $task->agent_id,
                            'result' => $this->taskResult($task),
                        ],
                        static fn (mixed $value): bool => $value !== null,
                    ))
                    ->all(),
            ])
            ->all();
    }

    /**
     * Plans that just closed, so their output survives long enough to be asked about.
     *
     * @param Collection<int, Plan> $open Already in the bundle; excluded so a plan is never listed twice.
     * @return Collection<int, Plan>
     */
    private function recentlyClosedPlans(Project $project, Collection $open): Collection
    {
        return $project->plans()
            ->whereNotIn('id', $open->modelKeys())
            ->with('tasks')
            ->orderByDesc('updated_at')
            ->limit(self::RECENTLY_CLOSED_PLANS)
            ->get();
    }

    /** What the task actually produced, for tasks that produced something. */
    private function taskResult(Task $task): ?string
    {
        return $task->workerSummaryExcerpt(self::TASK_RESULT_CHAR_CAP);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    /**
     * @return array<int, int>
     */
    private function planChannelIds(Project $project): array
    {
        return Channel::query()
            ->whereIn('entity_id', $project->plans()->pluck('id')->map(strval(...)))
            ->whereIn('entity_namespace', [Plan::class, SystemModules::getLegacyNamespace(Plan::class)])
            ->where('is_deleted', 0)
            ->pluck('id')
            ->all();
    }

    private function recentMessages(Project $project, int $limit): array
    {
        // The project's own channels AND its plans' Activities channels. A worker reports on the plan
        // it was given, not on the project, so reading only project channels made every reply an agent
        // wrote invisible here — including the one carrying the file the PM was later asked for.
        $channelIds = $project->channels()->pluck('id')
            ->concat($this->planChannelIds($project))
            ->unique()
            ->values()
            ->all();

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
