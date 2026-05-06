<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Casts\Json;
use Baka\Traits\SoftDeletesTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Factories\AgentTypeFactory;
use Kanvas\Intelligence\Models\BaseModel;
use Override;

/**
 * @property int $id
 * @property string $uuid
 * @property int $app_id
 * @property string $name
 * @property string|null $description
 * @property array|null $config
 * @property string|null $role
 * @property bool $is_active
 * @property bool $is_published
 * @property bool $is_multi_agent
 * @property array|null $multi_agent_list
 * @property bool $is_deleted
 */
class AgentType extends BaseModel
{
    use SoftDeletesTrait;
    use UuidTrait;

    protected $fillable = [
        'uuid',
        'app_id',
        'name',
        'description',
        'handler',
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

    public function scopeFromAppOrGlobal(Builder $query, mixed $app = null): Builder
    {
        $app = $app instanceof Apps ? $app : app(Apps::class);

        return $query->whereIn('apps_id', [0, $app->getId()]);
    }

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }

    public function tools(): BelongsToMany
    {
        return $this->belongsToMany(
            \Kanvas\NervousSystem\Capability\Models\Tool::class,
            'nervous_system_tool_agent_types',
            'agent_type_id',
            'tool_id'
        );
    }

    #[Override]
    protected static function newFactory()
    {
        return new AgentTypeFactory();
    }
}
