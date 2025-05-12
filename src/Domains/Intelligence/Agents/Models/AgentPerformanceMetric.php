<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Intelligence\Models\BaseModel;

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
