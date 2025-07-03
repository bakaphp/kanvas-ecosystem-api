<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Casts\Json;
use Baka\Traits\SoftDeletesTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Intelligence\Models\BaseModel;

class AgentType extends BaseModel
{
    use SoftDeletesTrait;
    use UuidTrait;

    protected $fillable = [
        'uuid',
        'app_id',
        'name',
        'description',
        'config',
        'role',
        'is_active',
        'is_published',
        'is_multi_agent',
        'multi_agent_list',
    ];

    protected $casts = [
        'config' => Json::class,
        'multi_agent_list' => Json::class,
        'is_active' => 'boolean',
        'is_published' => 'boolean',
        'is_multi_agent' => 'boolean',
    ];

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }
}
