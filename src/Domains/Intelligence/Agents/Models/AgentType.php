<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Casts\Json;
use Baka\Contracts\AppInterface;
use Baka\Traits\DatabaseSearchableTrait;
use Baka\Traits\SoftDeletesTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Intelligence\Agents\Factories\AgentTypeFactory;
use Kanvas\Intelligence\Models\BaseModel;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Override;

/**
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property string $name
 * @property string|null $provider
 * @property string|null $handler
 * @property string|null $description
 * @property array|null $config
 * @property string|null $role
 * @property string|null $soul
 * @property string|null $instructions
 * @property string|null $output_format
 * @property bool $is_active
 * @property bool $is_published
 * @property bool $is_multi_agent
 * @property bool $is_default
 * @property int $weight
 * @property array|null $multi_agent_list
 * @property bool $is_deleted
 */
class AgentType extends BaseModel
{
    use DatabaseSearchableTrait {
        search as public traitSearch;
    }
    use SoftDeletesTrait;
    use UuidTrait;

    protected $fillable = [
        'uuid',
        'apps_id',
        'name',
        'description',
        'provider',
        'handler',
        'config',
        'role',
        'soul',
        'instructions',
        'output_format',
        'is_active',
        'is_published',
        'is_multi_agent',
        'is_default',
        'weight',
        'multi_agent_list',
    ];

    protected $casts = [
        'config' => Json::class,
        'multi_agent_list' => Json::class,
        'is_active' => 'boolean',
        'is_published' => 'boolean',
        'is_multi_agent' => 'boolean',
        'is_default' => 'boolean',
    ];

    #[Override]
    public static function getById(mixed $id, ?AppInterface $app = null): static
    {
        $app = $app instanceof Apps ? $app : app(Apps::class);

        $record = static::where('id', $id)
            ->fromAppOrGlobal($app)
            ->notDeleted()
            ->first();

        if (! $record) {
            throw new ModelNotFoundException('No query results for model [' . static::class . "]. $id");
        }

        return $record;
    }

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
            Tool::class,
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

    public function searchableAs(): string
    {
        $app = app(Apps::class);
        $customIndex = $app->get('app_custom_agent_type_index') ?? null;

        return config('scout.prefix') . ($customIndex ?? 'agent_type_index');
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'apps_id' => $this->apps_id,
            'name' => $this->name,
            'description' => $this->description,
            'provider' => $this->provider,
            'handler' => $this->handler,
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return ! $this->isDeleted();
    }

    /**
     * AgentType is app-scoped with a "global" lane at apps_id=0 (templates
     * shared across all apps). Mirror `fromAppOrGlobal` here so searches
     * don't leak between apps but still include globals.
     */
    public static function search($query = '', $callback = null)
    {
        return self::traitSearch($query, $callback)
            ->whereIn('apps_id', [0, app(Apps::class)->getId()]);
    }
}
