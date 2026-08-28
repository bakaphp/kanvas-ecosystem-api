<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Models;

use Baka\Casts\Json;
use Baka\Traits\HasLightHouseCache;
use Baka\Traits\UuidTrait;
use Dyrynda\Database\Support\CascadeSoftDeletes;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Contracts\HandlesAgentMention;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentSwarm;
use Kanvas\NervousSystem\Ledger\Enums\LedgerConfigurationEnum;
use Kanvas\NervousSystem\Ledger\Traits\EmitsLedgerEventsForEntity;
use Kanvas\NervousSystem\Models\BaseModel;
use Kanvas\NervousSystem\Plan\Enums\PlanChangeTypeEnum;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Events\PlanBroadcast;
use Kanvas\NervousSystem\Plan\Observers\PlanObserver;
use Kanvas\NervousSystem\Plan\Traits\TruncatesTitleTrait;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForProjectJob;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Project\Services\ProjectMentionTriggerService;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Tags\Traits\HasTagsTrait;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;
use Override;
use Throwable;

/**
 * Persistent record of an agent's plan — what it's pursuing, broken into tasks,
 * with progress tracking and optional human approval gating.
 *
 * Absorbs the AgentTask concept from the AI Agent Architecture doc §10:
 * task_type → plan_type, requires_review → requires_human_approval, etc.
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int|null $agent_id
 * @property int|null $swarm_id
 * @property bool $is_swarm_mission
 * @property int|null $users_id
 * @property int|null $parent_plan_id
 * @property int|null $project_id
 * @property int|null $assigned_users_id
 * @property string|null $entity_namespace
 * @property int|null $entity_id
 * @property string $plan_type
 * @property string $title
 * @property string|null $description
 * @property string $status
 * @property array|null $capability_declined_agent_ids
 * @property int $priority
 * @property \Illuminate\Support\Carbon|null $deadline_at
 * @property int $completion_pct
 * @property array|null $input
 * @property array|null $output
 * @property string|null $confidence_score
 * @property bool $requires_human_approval
 * @property int|null $approved_by_user_id
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property string|null $review_outcome
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property string|null $error_message
 * @property string|null $impact_summary
 * @property string|null $status_pill
 * @property bool $is_deleted
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Project|null $project
 */
#[ObservedBy([PlanObserver::class])]
class Plan extends BaseModel implements HandlesAgentMention
{
    use CascadeSoftDeletes;
    use EmitsLedgerEventsForEntity;
    use HasLightHouseCache;
    use HasTagsTrait;
    use TruncatesTitleTrait;
    use UuidTrait;

    protected $cascadeDeletes = ['tasks', 'children'];

    #[Override]
    public function getGraphTypeName(): string
    {
        return 'NervousSystemPlan';
    }

    protected $table = 'nervous_system_plans';

    public $timestamps = true;

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'apps_id' => 'integer',
            'companies_id' => 'integer',
            'agent_id' => 'integer',
            'assigned_users_id' => 'integer',
            'swarm_id' => 'integer',
            'users_id' => 'integer',
            'parent_plan_id' => 'integer',
            'entity_id' => 'integer',
            'priority' => 'integer',
            'completion_pct' => 'integer',
            'approved_by_user_id' => 'integer',
            'input' => Json::class,
            'output' => Json::class,
            'capability_declined_agent_ids' => Json::class,
            'requires_human_approval' => 'boolean',
            'is_swarm_mission' => 'boolean',
            'is_deleted' => 'boolean',
            'deadline_at' => 'datetime',
            'approved_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'plan_id', 'id')->where('is_deleted', 0);
    }

    /**
     * A Plan is a valid agent-conversation entity (a worker is woken with the Plan in scope), and the
     * chat/context path calls getName() on the entity the way it does for a Lead/People. Expose the
     * title so a Plan-scoped run doesn't fatal on the missing method.
     */
    public function getName(): string
    {
        return $this->title ?? '';
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_plan_id', 'id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_plan_id', 'id')->where('is_deleted', 0);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id', 'id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'assigned_users_id', 'id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'id');
    }

    #[Override]
    public function handleAgentMention(Agent $agent, Message $mention, int $threadRootId): ?object
    {
        if ($this->is_deleted) {
            return null;
        }

        $project = $this->project;
        if ($project === null || $project->is_deleted || $project->agent_id !== $agent->getId()) {
            return null;
        }

        $focus = sprintf(
            'You were @mentioned on the thread of PLAN #%d "%s" (status: %s). The person is asking about '
            . 'THIS plan specifically — read this plan and its tasks in the Context and answer about it '
            . 'directly. Do NOT give a whole-project status unless they ask for one.',
            $this->getId(),
            $this->title,
            $this->status,
        );

        return new WakeAgentForProjectJob(
            $project,
            WakeAgentForProjectJob::REASON_MENTION,
            new ProjectMentionTriggerService()->buildTrigger($mention, $focus),
            $threadRootId,
        );
    }

    /**
     * An agent-assigned plan's lifecycle events are the agent's work, so attribute them to the
     * Agent even though a human (users_id) may have created/owns it. Only fall back to the human
     * owner when there's no agent. Override of the trait default — no #[Override] (concrete method).
     */
    protected function resolveDefaultActorType(): string
    {
        if ($this->agent_id !== null) {
            return 'Agent';
        }
        if ($this->users_id !== null) {
            return 'User';
        }

        return 'System';
    }

    protected function resolveDefaultActorId(): ?int
    {
        return $this->agent_id ?? $this->users_id ?? null;
    }

    public function swarm(): BelongsTo
    {
        return $this->belongsTo(AgentSwarm::class, 'swarm_id', 'id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'approved_by_user_id', 'id');
    }

    /**
     * Status pill for the dashboard mission card. Honors an explicit
     * column value (allows agents/UI to set "LEARNING" for research-style
     * missions); falls back to a computed default from completion_pct
     * vs deadline progress when null.
     *
     * Default formula:
     *   - no deadline → ON_TRACK
     *   - past deadline & not done → BEHIND
     *   - completion_pct >= expected_pct + 25 → EXCEEDING_TARGET
     *   - completion_pct >= expected_pct + 10 → AHEAD_OF_TARGET
     *   - completion_pct >= expected_pct - 10 → ON_TRACK
     *   - else → BEHIND
     */
    public function getStatusPillAttribute(?string $value): ?string
    {
        if ($value !== null && $value !== '') {
            return $value;
        }

        if ($this->deadline_at === null) {
            return 'ON_TRACK';
        }

        $now = Carbon::now();

        if ($now->gt($this->deadline_at) && $this->status !== 'done') {
            return 'BEHIND';
        }

        $startedAt = $this->started_at ?? $this->created_at;
        if ($startedAt === null) {
            return 'ON_TRACK';
        }

        $totalSeconds = $this->deadline_at->diffInSeconds($startedAt, true);
        if ($totalSeconds <= 0) {
            return 'ON_TRACK';
        }

        $elapsedSeconds = max(0, $now->diffInSeconds($startedAt, true));
        $expectedPct = (int) min(100, ($elapsedSeconds / $totalSeconds) * 100);
        $delta = $this->completion_pct - $expectedPct;

        return match (true) {
            $delta >= 25 => 'EXCEEDING_TARGET',
            $delta >= 10 => 'AHEAD_OF_TARGET',
            $delta >= -10 => 'ON_TRACK',
            default => 'BEHIND',
        };
    }

    /**
     * Required because Social\Channels\Models\Channel stores `entity_id` as
     * varchar — Eloquent won't cast int → string for the FK pairing.
     */
    public function getStringIdAttribute(): string
    {
        return (string) $this->id;
    }

    /**
     * The channel the plan was asked for in, when there was one.
     *
     * Distinct from `socialChannels`, which are the channels the plan OWNS. This one belongs to a
     * conversation that existed first and outlives the plan — it is where a person is actually
     * listening, so it is where the outcome has to be reported.
     */
    public function originChannel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'origin_channel_id');
    }

    public function socialChannels(): HasMany
    {
        return $this->hasMany(Channel::class, 'entity_id', 'string_id')
            ->whereIn(
                'entity_namespace',
                [
                    self::class,
                    SystemModules::getLegacyNamespace(self::class),
                ],
            )
            ->where('is_deleted', 0);
    }

    public function recentActivityMessages(int $limit = 15): Collection
    {
        $channel = $this->socialChannels()->first();

        return $channel === null
            ? new Collection()
            : $channel->messages()->orderByDesc('messages.id')->limit($limit)->get();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn(
            'status',
            array_map(fn ($s) => $s->value, PlanStatusEnum::openStatuses()),
        );
    }

    public function scopeAwaitingApproval(Builder $query): Builder
    {
        return $query->where('status', PlanStatusEnum::AWAITING_APPROVAL->value);
    }

    public function scopeForEntity(Builder $query, string $entityClass, int $entityId): Builder
    {
        return $query
            ->where('entity_namespace', $entityClass)
            ->where('entity_id', $entityId);
    }

    public function broadcastChange(
        PlanChangeTypeEnum $changeType,
        ?Task $task = null,
        ?string $previousStatus = null,
        bool $fromSync = false,
    ): void {
        try {
            $value = $this->app->get(LedgerConfigurationEnum::BROADCAST_PLAN_EVENTS->value);

            // Default is ON. Only suppress when the app explicitly stores a falsy value
            // (false / 0 / "0" / ""). null / missing → broadcast.
            if ($value !== null && ! (bool) $value) {
                return;
            }

            PlanBroadcast::dispatch(
                $this,
                $changeType,
                $task,
                $previousStatus,
                $fromSync,
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function recomputeCompletionPct(): int
    {
        $total = (int) $this->tasks()->where('is_deleted', 0)->count();

        if ($total === 0) {
            $this->completion_pct = 0;
            $this->saveOrFail();

            return 0;
        }

        $done = (int) $this->tasks()
            ->where('is_deleted', 0)
            ->whereIn(
                'status',
                array_map(fn ($s) => $s->value, TaskStatusEnum::completedStatuses()),
            )
            ->count();

        $pct = intdiv($done * 100, $total);

        $this->completion_pct = $pct;
        $this->saveOrFail();

        return $pct;
    }
}
