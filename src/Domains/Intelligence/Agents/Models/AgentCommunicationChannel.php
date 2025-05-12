<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Intelligence\Models\BaseModel;

class AgentCommunicationChannel extends BaseModel
{
    use UuidTrait;

    protected $fillable = [
        'agent_id',
        'communication_channel_id',
        'entry_point',
        'config',
    ];

    protected $casts = [
        'config' => Json::class,
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function communicationChannel(): BelongsTo
    {
        return $this->belongsTo(CommunicationChannel::class);
    }
}
