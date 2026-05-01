<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\NervousSystem\Ledger\Traits\EmitsLedgerEventsForEntity;
use Kanvas\NervousSystem\Models\BaseModel;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Override;

/**
 * One task inside a Plan. State transitions emit ledger events.
 *
 * @property int $id
 * @property string $uuid
 * @property int $plan_id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $sequence
 * @property string $title
 * @property string|null $description
 * @property string $status
 * @property array|null $result
 * @property string|null $blocked_reason
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property bool $is_deleted
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Task extends BaseModel
{
    use EmitsLedgerEventsForEntity;
    use UuidTrait;

    protected $table = 'nervous_system_tasks';

    public $timestamps = true;

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'plan_id' => 'integer',
            'apps_id' => 'integer',
            'companies_id' => 'integer',
            'sequence' => 'integer',
            'result' => Json::class,
            'is_deleted' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'id');
    }

    /**
     * Tasks don't carry their own users_id/agent_id; the actor of a task
     * lifecycle event is the parent plan's owner. Override of the trait's
     * default — no #[Override] attribute because the trait method is
     * concrete (PHP would fatal).
     */
    protected function resolveDefaultActorType(): string
    {
        if ($this->plan?->users_id !== null) {
            return 'User';
        }
        if ($this->plan?->agent_id !== null) {
            return 'Agent';
        }

        return 'System';
    }

    protected function resolveDefaultActorId(): ?int
    {
        return $this->plan?->users_id ?? $this->plan?->agent_id ?? null;
    }

    public function scopeStalled(Builder $query, int $minutes): Builder
    {
        return $query
            ->where('status', TaskStatusEnum::IN_PROGRESS->value)
            ->where('started_at', '<', now()->subMinutes($minutes))
            ->where('is_deleted', 0);
    }
}
