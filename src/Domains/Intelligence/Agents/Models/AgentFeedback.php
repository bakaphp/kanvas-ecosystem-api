<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Intelligence\Models\BaseModel;

class AgentFeedback extends BaseModel
{
    use UuidTrait;

    protected $fillable = [
        'agent_history_id',
        'user_id',
        'rating',
        'feedback_text',
    ];

    public function agentHistory(): BelongsTo
    {
        return $this->belongsTo(AgentHistory::class);
    }
}
