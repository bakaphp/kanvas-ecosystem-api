<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Models;

use Baka\Traits\KanvasModelTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * One finished attempt of a delegated task, as the parent sees it. Append-only: a reopened child
 * writes a new row under its new attempt, never an update to this one.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $parent_task_id
 * @property int $child_task_id
 * @property string $attempt_id
 * @property int|null $revision
 * @property string $status
 * @property string|null $summary
 * @property int|null $agent_task_session_id
 * @property Carbon|null $delivered_at
 * @property Carbon $created_at
 */
class TaskChildOutcome extends Model
{
    use KanvasModelTrait;

    protected $connection = 'intelligence';

    protected $table = 'nervous_system_task_child_outcomes';

    public $timestamps = false;

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'parent_task_id' => 'integer',
            'child_task_id' => 'integer',
            'revision' => 'integer',
            'agent_task_session_id' => 'integer',
            'delivered_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_task_id', 'id');
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'child_task_id', 'id');
    }

    /**
     * Claims delivery for exactly one caller. The wake job and the reconciler can both hold the same
     * row; whichever updates the null first owns the wake, the other sees 0 rows and stops.
     */
    public function claimDelivery(): bool
    {
        return static::query()
            ->whereKey($this->getKey())
            ->whereNull('delivered_at')
            ->update(['delivered_at' => Carbon::now()]) === 1;
    }

    public function releaseDelivery(): void
    {
        static::query()->whereKey($this->getKey())->update(['delivered_at' => null]);
    }

    /** Outcomes whose after-commit wake never ran — committed, then the worker died before the dispatch. */
    public function scopeUndeliveredFor(Builder $query, int $minutes): Builder
    {
        return $query
            ->whereNull('delivered_at')
            ->where('created_at', '<', Carbon::now()->subMinutes($minutes));
    }
}
