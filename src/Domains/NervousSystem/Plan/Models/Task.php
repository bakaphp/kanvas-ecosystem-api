<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kanvas\Approvals\Traits\HasApprovals;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Ledger\Traits\EmitsLedgerEventsForEntity;
use Kanvas\NervousSystem\Models\BaseModel;
use Kanvas\NervousSystem\Plan\Concerns\TruncatesTitle;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Observers\TaskObserver;
use Kanvas\Users\Models\Users;
use Override;

/**
 * One task inside a Plan. State transitions emit ledger events.
 *
 * @property int $id
 * @property string $uuid
 * @property int $plan_id
 * @property int|null $parent_task_id
 * @property int|null $agent_id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $sequence
 * @property string $title
 * @property string|null $description
 * @property string $status
 * @property array|null $result
 * @property string|null $blocked_reason
 * @property Carbon|null $wake_at
 * @property int|null $waiting_on_users_id
 * @property int|null $waiting_on_channel_id
 * @property string|null $current_attempt_id
 * @property int $revision
 * @property int|null $parent_revision
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property bool $is_deleted
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 */
#[ObservedBy([TaskObserver::class])]
class Task extends BaseModel
{
    use HasApprovals;
    use EmitsLedgerEventsForEntity;
    use TruncatesTitle;
    use UuidTrait;

    protected $table = 'nervous_system_tasks';

    public $timestamps = true;

    protected $guarded = [];

    protected $attributes = [
        'is_deleted' => 0,
        'revision' => 1,
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'plan_id' => 'integer',
            'parent_task_id' => 'integer',
            'agent_id' => 'integer',
            'revision' => 'integer',
            'parent_revision' => 'integer',
            'waiting_on_users_id' => 'integer',
            'waiting_on_channel_id' => 'integer',
            'apps_id' => 'integer',
            'companies_id' => 'integer',
            'sequence' => 'integer',
            'result' => Json::class,
            'is_deleted' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'wake_at' => 'datetime',
        ];
    }

    /**
     * Explicit gating only. A task is saved on every status change of every plan in the platform, and a
     * policy lookup on each of those is a cost with no reader — the only approval a task raises is the
     * one the coding harness asks for before a push.
     *
     * No #[Override]: this overrides a CONCRETE trait method, which PHP does not treat as a valid
     * override target — the attribute fatals at class load.
     */
    protected static function approvalUsesLifecycleTriggers(): bool
    {
        return false;
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'id');
    }

    /**
     * A Task is an agent-conversation entity (an assignee is woken with the Task in scope), and the
     * chat/context path calls getName() on the entity as it does for a Lead/People. Expose the title.
     */
    public function getName(): string
    {
        return $this->title ?? '';
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id', 'id');
    }

    /** The task that delegated this one and is resumed by its outcome. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_task_id', 'id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_task_id', 'id')->where('is_deleted', 0);
    }

    public function childOutcomes(): HasMany
    {
        return $this->hasMany(TaskChildOutcome::class, 'parent_task_id', 'id');
    }

    /** The person who owes the answer this task is parked on. */
    public function waitingOn(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'waiting_on_users_id', 'id');
    }

    public function scopeWaiting(Builder $query): Builder
    {
        return $query->where('status', TaskStatusEnum::WAITING->value)->where('is_deleted', 0);
    }

    /** Waiting tasks parked on this person, or asked in this channel. */
    public function scopeWaitingOnPerson(Builder $query, Users $person, ?int $channelId = null): Builder
    {
        return $query
            ->waiting()
            ->where(
                fn (Builder $q): Builder => $q
                    ->where('waiting_on_users_id', $person->getId())
                    ->when($channelId !== null, fn (Builder $c): Builder => $c->orWhere('waiting_on_channel_id', $channelId)),
            );
    }

    public function hasOpenChildren(): bool
    {
        return $this->children()->open()->exists();
    }

    /** Not yet at a terminal status — pending, in progress or waiting. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', TaskStatusEnum::terminalStatusValues());
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, TaskStatusEnum::terminalStatusValues(), true);
    }

    /**
     * The agent does the work, so an agent-assigned task's lifecycle events are attributed to the
     * Agent (own agent_id, else the parent plan's) even though a human may have created the plan.
     * Only when there's no agent at all do we fall back to the plan's human owner. Override of the
     * trait default — no #[Override] (the trait method is concrete; PHP would fatal).
     */
    protected function resolveDefaultActorType(): string
    {
        if ($this->agent_id !== null || $this->plan?->agent_id !== null) {
            return 'Agent';
        }
        if ($this->plan?->users_id !== null) {
            return 'User';
        }

        return 'System';
    }

    protected function resolveDefaultActorId(): ?int
    {
        return $this->agent_id ?? $this->plan?->agent_id ?? $this->plan?->users_id ?? null;
    }

    /**
     * What the worker reported when it finished this task, or null if nothing was recorded.
     */
    public function workerSummary(): ?string
    {
        $result = is_array($this->result) ? $this->result : [];
        $summary = trim((string) ($result['worker_summary'] ?? $result['note'] ?? ''));

        return $summary !== '' ? $summary : null;
    }

    /**
     * The summary trimmed to fit, keeping BOTH ends.
     *
     * These reports narrate first and answer last as often as not — one lead audit wrote four sentences
     * of method before "a total matching count of 33". Cutting the head off at a fixed length keeps the
     * preamble and throws away the number, which is the one part anyone asks about afterwards.
     */
    public function workerSummaryExcerpt(int $cap): ?string
    {
        $summary = $this->workerSummary();

        if ($summary === null || mb_strlen($summary) <= $cap) {
            return $summary;
        }

        return mb_substr($summary, 0, (int) round($cap * 0.6))
            . ' […] '
            . mb_substr($summary, -(int) round($cap * 0.4));
    }

    /**
     * Waiting tasks nothing is going to end: the wake is overdue, or there never was one — and no
     * delegated child is still running, because a parent waiting on live work is waiting as planned.
     */
    public function scopeOverdueWake(Builder $query): Builder
    {
        return $query
            ->waiting()
            ->where(fn (Builder $q): Builder => $q->whereNull('wake_at')->orWhere('wake_at', '<=', now()))
            ->whereDoesntHave('children', fn (Builder $q): Builder => $q->open());
    }

    /** The opposite of overdue: a wait something is still going to end. */
    public function scopeWaitingAsPlanned(Builder $query): Builder
    {
        return $query
            ->waiting()
            ->where(
                fn (Builder $q): Builder => $q
                    ->where('wake_at', '>', now())
                    ->orWhereHas('children', fn (Builder $child): Builder => $child->open()),
            );
    }

    public function scopeStalled(Builder $query, int $minutes): Builder
    {
        return $query
            ->where('status', TaskStatusEnum::IN_PROGRESS->value)
            ->where('started_at', '<', now()->subMinutes($minutes))
            ->where('is_deleted', 0);
    }
}
