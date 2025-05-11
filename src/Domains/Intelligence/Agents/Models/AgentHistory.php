<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Intelligence\Models\BaseModel;

class AgentHistory extends BaseModel
{
    use UuidTrait;

    protected $fillable = [
        'uuid',
        'agent_id',
        'company_id',
        'app_id',
        'company_task_engagement_item_id',
        'message_id',
        'entity_namespace',
        'entity_id',
        'context',
        'config',
        'external_reference',
        'input',
        'output',
        'error',
    ];

    protected $casts = [
        'config' => Json::class,
        'external_reference' => Json::class,
        'input' => Json::class,
        'output' => Json::class,
        'error' => Json::class,
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(AgentFeedback::class);
    }

    public function performanceMetrics(): HasMany
    {
        return $this->hasMany(AgentPerformanceMetric::class);
    }
}
