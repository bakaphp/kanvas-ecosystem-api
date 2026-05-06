<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\NervousSystem\Ledger\Traits\EmitsLedgerEventsForEntity;
use Kanvas\NervousSystem\Models\BaseModel;
use Override;

/**
 * Tool catalog entry. apps_id=0 means a global tool available to all apps.
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property string $name
 * @property string|null $description
 * @property string $tool_type
 * @property string|null $handler
 * @property array|null $input_schema
 * @property array|null $output_schema
 * @property array|null $requires_permission
 * @property array $frameworks
 * @property string $version
 * @property bool $is_active
 * @property bool $is_deleted
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Tool extends BaseModel
{
    use EmitsLedgerEventsForEntity;
    use UuidTrait;

    protected $table = 'nervous_system_tools';

    public $timestamps = true;

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'apps_id' => 'integer',
            'input_schema' => Json::class,
            'output_schema' => Json::class,
            'requires_permission' => Json::class,
            'frameworks' => Json::class,
            'is_active' => 'boolean',
            'is_deleted' => 'boolean',
        ];
    }

    public function agentTypes(): BelongsToMany
    {
        return $this->belongsToMany(
            AgentType::class,
            'nervous_system_tool_agent_types',
            'tool_id',
            'agent_type_id'
        );
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', 1)->where('is_deleted', 0);
    }

    public function scopeForFramework(Builder $query, string $framework): Builder
    {
        return $query->whereJsonContains('frameworks', $framework);
    }

    public function scopeForApp(Builder $query, int $appsId): Builder
    {
        return $query->whereIn('apps_id', [0, $appsId]);
    }
}
