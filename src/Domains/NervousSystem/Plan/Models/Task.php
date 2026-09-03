<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Ledger\Traits\EmitsLedgerEventsForEntity;
use Kanvas\NervousSystem\Models\BaseModel;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Traits\TruncatesTitleTrait;
use Override;

/**
 * One task inside a Plan. State transitions emit ledger events.
 *
 * @property int $id
 * @property string $uuid
 * @property int $plan_id
 * @property int|null $agent_id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $sequence
 * @property string $title
 * @property string|null $description
 * @property string $status
 * @property array|null $result
 * @property string|null $blocked_reason
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property bool $is_deleted
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 */
class Task extends BaseModel
{
    use EmitsLedgerEventsForEntity;
    use TruncatesTitleTrait;
    use UuidTrait;

    protected $table = 'nervous_system_tasks';

    public $timestamps = true;

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'plan_id' => 'integer',
            'agent_id' => 'integer',
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
        $summary = trim((string) ($result['worker_summary'] ?? ''));

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

    public function scopeStalled(Builder $query, int $minutes): Builder
    {
        return $query
            ->where('status', TaskStatusEnum::IN_PROGRESS->value)
            ->where('started_at', '<', now()->subMinutes($minutes))
            ->where('is_deleted', 0);
    }
}
