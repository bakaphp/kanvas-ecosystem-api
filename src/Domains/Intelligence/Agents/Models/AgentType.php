<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Casts\Json;
use Baka\Traits\SoftDeletesTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    #[Override]
    protected static function newFactory()
    {
        return new AgentTypeFactory();
    }
}
