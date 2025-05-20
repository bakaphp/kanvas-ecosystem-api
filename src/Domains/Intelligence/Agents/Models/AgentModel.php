<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Intelligence\Models\BaseModel;

class AgentModel extends BaseModel
{
    use UuidTrait;

    protected $fillable = [
        'uuid',
        'app_id',
        'name',
        'config',
        'is_active',
        'is_published',
    ];

    protected $casts = [
        'config' => Json::class,
        'is_active' => 'boolean',
        'is_published' => 'boolean',
    ];

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }
}
