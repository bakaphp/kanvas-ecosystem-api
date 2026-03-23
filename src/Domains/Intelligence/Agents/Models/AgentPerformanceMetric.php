<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Models\BaseModel;

/**
 * @property int $id
 * @property int $agent_id
 * @property int $agent_history_id
 * @property string $metric_type
 * @property float $value
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 */
class AgentPerformanceMetric extends BaseModel
{
    use UuidTrait;

    protected $fillable = [
        'agent_id',
        'agent_history_id',
        'metric_type',
        'value',
        'period_start',
        'period_end',
    ];

    protected $casts = [
        'value' => 'float',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function agentHistory(): BelongsTo
    {
        return $this->belongsTo(AgentHistory::class);
    }
}
