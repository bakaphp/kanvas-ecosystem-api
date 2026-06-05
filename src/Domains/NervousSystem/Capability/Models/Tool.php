<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\KanvasModules\Models\KanvasModule;
use Kanvas\NervousSystem\Ledger\Traits\EmitsLedgerEventsForEntity;
use Kanvas\NervousSystem\Models\BaseModel;
use Override;

/**
 * Tool catalog entry. App-scoped with a shared platform lane at apps_id=0:
 * `fromAppOrGlobal` resolves a tenant's own tools plus platform globals
 * (apps_id=0, available to every app). Per-app tools (apps_id>0) never
 * cross-tenant — use `fromApp` when globals must be excluded.
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property string $name
 * @property string|null $description
 * @property string $tool_type
 * @property int|null $tool_category_id
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

    // Cross-DB: KanvasModule lives on ecosystem; pivot has no FK to it.
    public function kanvasModules(): BelongsToMany
    {
        return $this->belongsToMany(
            KanvasModule::class,
            'nervous_system_tool_kanvas_modules',
            'tool_id',
            'kanvas_modules_id'
        )->withPivot('direction')->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', 1)->where('is_deleted', 0);
    }

    public function scopeForFramework(Builder $query, string $framework): Builder
    {
        return $query->whereJsonContains('frameworks', $framework);
    }

    public function scopeFromAppOrGlobal(Builder $query, mixed $app = null): Builder
    {
        $app = $app instanceof Apps ? $app : app(Apps::class);

        return $query->whereIn('apps_id', [0, $app->getId()]);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ToolCategory::class, 'tool_category_id', 'id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agents_id');
    }

    public function scopeInCategory(Builder $query, mixed $category): Builder
    {
        // Accept either a category id (int / string) or a slug. Slug lookups
        // hit apps_id=0 first; pass a ToolCategory model to skip the lookup.
        if ($category instanceof ToolCategory) {
            return $query->where('tool_category_id', $category->getKey());
        }

        if (is_numeric($category)) {
            return $query->where('tool_category_id', (int) $category);
        }

        return $query->whereIn(
            'tool_category_id',
            ToolCategory::query()
                ->where('slug', (string) $category)
                ->fromAppOrGlobal()
                ->pluck('id'),
        );
    }
}
