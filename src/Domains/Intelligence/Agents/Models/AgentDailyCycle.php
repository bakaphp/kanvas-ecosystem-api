<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Intelligence\Models\BaseModel;
use Override;

/**
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $agent_id
 * @property \Illuminate\Support\Carbon $cycle_date
 * @property \Illuminate\Support\Carbon|null $awake_started_at
 * @property \Illuminate\Support\Carbon|null $awake_ended_at
 * @property \Illuminate\Support\Carbon|null $sleep_started_at
 * @property \Illuminate\Support\Carbon|null $sleep_ended_at
 * @property int $awake_duration_minutes
 * @property int $sleep_duration_minutes
 * @property int $proactive_actions_count
 * @property int $events_processed_count
 * @property \Illuminate\Support\Carbon|null $last_action_at
 * @property string|null $last_action_label
 * @property string|null $morning_briefing
 * @property array|null $proposed_actions
 * @property array|null $skills_emerged
 * @property string $self_improvement_score
 * @property string|null $signed_by_text
 * @property bool $is_deleted
 */
class AgentDailyCycle extends BaseModel
{
    protected $table = 'agent_daily_cycles';

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'apps_id' => 'integer',
            'companies_id' => 'integer',
            'agent_id' => 'integer',
            'cycle_date' => 'date',
            'awake_started_at' => 'datetime',
            'awake_ended_at' => 'datetime',
            'sleep_started_at' => 'datetime',
            'sleep_ended_at' => 'datetime',
            'awake_duration_minutes' => 'integer',
            'sleep_duration_minutes' => 'integer',
            'proactive_actions_count' => 'integer',
            'events_processed_count' => 'integer',
            'last_action_at' => 'datetime',
            'proposed_actions' => Json::class,
            'skills_emerged' => Json::class,
            'is_deleted' => 'boolean',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id', 'id');
    }

    public function phases(): HasMany
    {
        return $this->hasMany(AgentSleepPhase::class, 'agent_daily_cycle_id', 'id')
            ->orderBy('started_at');
    }

    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->where('cycle_date', $date);
    }

    /**
     * Average ledger events per awake hour for this cycle. Derived from the
     * columns the cron already wrote — no extra query at read time. Drives
     * the dashboard HEARTBEAT card with units "X/h".
     */
    public function eventsPerHour(): float
    {
        if ($this->awake_duration_minutes <= 0) {
            return 0.0;
        }

        $hours = (float) $this->awake_duration_minutes / 60.0;

        return round((float) $this->events_processed_count / $hours, 1);
    }
}
