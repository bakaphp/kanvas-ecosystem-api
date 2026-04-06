<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Models\BaseModel;
use Kanvas\Users\Models\Users;

/**
 * @property int $id
 * @property int $agent_id
 * @property string $version
 * @property array|null $config
 * @property string|null $changes
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property bool $is_active
 */
class AgentVersion extends BaseModel
{
    use UuidTrait;

    public $timestamps = false;

    protected $fillable = [
        'agent_id',
        'version',
        'config',
        'changes',
        'created_by',
        'created_at',
        'is_active',
    ];

    protected $casts = [
        'config' => Json::class,
        'is_active' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'created_by');
    }
}
