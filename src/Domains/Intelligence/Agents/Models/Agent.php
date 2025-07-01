<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\ActionEngine\Tasks\Models\TaskList;
use Kanvas\Intelligence\Models\BaseModel;

class Agent extends BaseModel
{
    use UuidTrait;
    protected $fillable = [
        'uuid',
        'app_id',
        'company_id',
        'agent_type_id',
        'user_id',
        'description',
        'config',
        'company_task_list_id',
        'role',
        'agent_model_id',
        'is_active',
    ];

    protected $casts = [
        'config' => Json::class,
        'role' => Json::class,
        'is_active' => 'boolean',
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(AgentType::class, 'agent_type_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AgentModel::class, 'agent_model_id');
    }

    public function companyTaskList(): BelongsTo
    {
        return $this->belongsTo(TaskList::class, 'company_task_list_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(AgentHistory::class);
    }

    public function communicationChannels(): BelongsToMany
    {
        return $this->belongsToMany(CommunicationChannel::class, 'agent_communication_channels')
            ->withPivot('entry_point', 'config')
            ->withTimestamps();
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AgentVersion::class);
    }

    public function performanceMetrics(): HasMany
    {
        return $this->hasMany(AgentPerformanceMetric::class);
    }
}
