<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Scheduling\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Ledger\Traits\EmitsLedgerEventsForEntity;
use Kanvas\NervousSystem\Models\BaseModel;
use Kanvas\NervousSystem\Scheduling\Enums\ScheduledActionStatusEnum;
use Kanvas\Users\Models\Users;
use Override;

/**
 * A future action an agent scheduled from a conversation — a one-off or a recurring
 * schedule. A recurring row is a single row that re-arms its own `run_at` from
 * `recurrence_cron` after each fire (never N pre-created rows).
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int $users_id
 * @property int|null $agent_id
 * @property string $action_type
 * @property string $status
 * @property Carbon $run_at
 * @property string $timezone
 * @property string|null $recurrence_cron
 * @property Carbon|null $recurrence_ends_at
 * @property int|null $max_occurrences
 * @property int $occurrences_count
 * @property array|null $payload
 * @property string|null $channel
 * @property string|null $session_uuid
 * @property string|null $source_entity_type
 * @property string|null $source_entity_id
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $claimed_at
 * @property Carbon|null $last_fired_at
 * @property Carbon|null $executed_at
 * @property bool $is_deleted
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 */
class ScheduledAction extends BaseModel
{
    use EmitsLedgerEventsForEntity;
    use UuidTrait;

    protected $table = 'nervous_system_scheduled_actions';

    public $timestamps = true;

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'apps_id' => 'integer',
            'companies_id' => 'integer',
            'users_id' => 'integer',
            'agent_id' => 'integer',
            'max_occurrences' => 'integer',
            'occurrences_count' => 'integer',
            'attempts' => 'integer',
            'is_deleted' => 'boolean',
            'payload' => Json::class,
            'run_at' => 'datetime',
            'recurrence_ends_at' => 'datetime',
            'claimed_at' => 'datetime',
            'last_fired_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id', 'id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id', 'id');
    }

    public function isRecurring(): bool
    {
        return $this->recurrence_cron !== null && $this->recurrence_cron !== '';
    }

    /**
     * The next UTC fire time from `$after`, computed from the cron in the row's own
     * timezone. Skips any occurrences already in the past relative to `$after` — so a
     * re-arm after downtime advances to the next FUTURE slot rather than replaying
     * missed ones (no catch-up storm).
     */
    public function nextRunAt(Carbon $after): CarbonImmutable
    {
        $next = new CronExpression((string) $this->recurrence_cron)
            ->getNextRunDate($after, 0, false, $this->timezone);

        return CarbonImmutable::instance($next)->utc();
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query
            ->where('is_deleted', 0)
            ->where('status', ScheduledActionStatusEnum::PENDING->value)
            ->where('run_at', '<=', Carbon::now());
    }

    public function scopePending(Builder $query): Builder
    {
        return $query
            ->where('is_deleted', 0)
            ->where('status', ScheduledActionStatusEnum::PENDING->value);
    }

    public function scopeForUser(Builder $query, int $usersId): Builder
    {
        return $query->where('users_id', $usersId);
    }

    /**
     * The scheduling agent is the semantic actor for ledger memory; fall back to the
     * recipient user, then System.
     */
    protected function resolveDefaultActorType(): string
    {
        return $this->agent_id !== null ? 'Agent' : 'User';
    }

    protected function resolveDefaultActorId(): ?int
    {
        return $this->agent_id ?? $this->users_id;
    }
}
