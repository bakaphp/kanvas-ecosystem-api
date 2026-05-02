<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Ledger\Traits\EmitsLedgerEventsForEntity;
use Kanvas\NervousSystem\Models\BaseModel;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\Users\Models\Users;
use Override;

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
 * @property int|null $users_id
 * @property int|null $parent_plan_id
 * @property string|null $entity_namespace
 * @property int|null $entity_id
 * @property string $plan_type
 * @property string $title
 * @property string|null $description
 * @property string $status
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
 * @property bool $is_deleted
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Plan extends BaseModel
{
    use EmitsLedgerEventsForEntity;
    use UuidTrait;

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
            'users_id' => 'integer',
            'parent_plan_id' => 'integer',
            'entity_id' => 'integer',
            'priority' => 'integer',
            'completion_pct' => 'integer',
            'approved_by_user_id' => 'integer',
            'input' => Json::class,
            'output' => Json::class,
            'requires_human_approval' => 'boolean',
            'is_deleted' => 'boolean',
            'deadline_at' => 'datetime',
            'approved_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'plan_id', 'id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_plan_id', 'id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_plan_id', 'id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id', 'id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'approved_by_user_id', 'id');
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

    /**
     * Recompute completion_pct from the current task state and persist it.
     * Returns the new percentage.
     */
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
