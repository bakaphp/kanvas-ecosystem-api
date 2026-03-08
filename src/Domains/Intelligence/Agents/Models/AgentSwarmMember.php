<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Intelligence\Models\BaseModel;

class AgentSwarmMember extends BaseModel
{
    protected $table = 'agent_swarm_members';

    protected $fillable = [
        'agent_swarm_id',
        'agent_id',
        'role',
        'config',
        'is_deleted',
    ];

    protected $casts = [
        'config' => Json::class,
    ];

    public function swarm(): BelongsTo
    {
        return $this->belongsTo(AgentSwarm::class, 'agent_swarm_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
