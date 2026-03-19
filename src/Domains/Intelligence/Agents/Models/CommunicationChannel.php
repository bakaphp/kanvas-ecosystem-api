<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Kanvas\Intelligence\Models\BaseModel;

/**
 * @property int $id
 * @property string $uuid
 * @property int $app_id
 * @property string $name
 * @property string|null $description
 * @property string|null $handler
 * @property array|null $config
 * @property bool $is_active
 * @property bool $is_published
 * @property bool $is_deleted
 */
class CommunicationChannel extends BaseModel
{
    use UuidTrait;

    protected $fillable = [
        'uuid',
        'app_id',
        'name',
        'description',
        'handler',
        'config',
        'is_active',
        'is_published',
    ];

    protected $casts = [
        'config' => Json::class,
        'is_active' => 'boolean',
        'is_published' => 'boolean',
    ];

    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'agent_communication_channels')
            ->withPivot('entry_point', 'config')
            ->withTimestamps();
    }
}
