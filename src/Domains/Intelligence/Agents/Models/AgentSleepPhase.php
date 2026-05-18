<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Intelligence\Models\BaseModel;
use Override;

/**
 * @property int $id
 * @property int $apps_id
 * @property int $agent_daily_cycle_id
 * @property int $agent_id
 * @property string $phase
 * @property \Illuminate\Support\Carbon $started_at
 * @property \Illuminate\Support\Carbon $ended_at
 * @property int $duration_minutes
 * @property string|null $outcome_summary
 * @property array|null $payload
 * @property bool $is_deleted
 */
class AgentSleepPhase extends BaseModel
{
    protected $table = 'agent_sleep_phases';

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'apps_id' => 'integer',
            'agent_daily_cycle_id' => 'integer',
            'agent_id' => 'integer',
            'duration_minutes' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'payload' => Json::class,
            'is_deleted' => 'boolean',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(AgentDailyCycle::class, 'agent_daily_cycle_id', 'id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id', 'id');
    }
}
