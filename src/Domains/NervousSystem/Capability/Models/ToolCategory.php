<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\Models\BaseModel;
use Override;

/**
 * @property int $id
 * @property int $apps_id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property string|null $icon
 * @property int $display_order
 * @property bool $is_active
 * @property bool $is_deleted
 */
class ToolCategory extends BaseModel
{
    protected $table = 'nervous_system_tool_categories';

    public $timestamps = true;

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'apps_id' => 'integer',
            'display_order' => 'integer',
            'is_active' => 'boolean',
            'is_deleted' => 'boolean',
        ];
    }

    public function tools(): HasMany
    {
        return $this->hasMany(Tool::class, 'tool_category_id', 'id');
    }

    /**
     * Categories are intentionally cross-tenant: platform-seeded ones live
     * at apps_id=0 and must surface in every app. Use this instead of the
     * default `fromApp` for any list/lookup the dashboard renders.
     */
    public function scopeFromAppOrGlobal(Builder $query, mixed $app = null): Builder
    {
        $id = $app instanceof Apps
            ? $app->getId()
            : (int) ($app ?? app(Apps::class)->getId());

        return $query->whereIn('apps_id', [0, $id]);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', 1)->where('is_deleted', 0);
    }

    /**
     * Restrict to categories that contain at least one active tool matching the
     * given framework (in the current app or the global apps_id=0 lane). No-op
     * when the framework is null/empty so the scope is safe to drive from a
     * nullable GraphQL `@scope` argument.
     */
    public function scopeForFramework(Builder $query, ?string $framework): Builder
    {
        if ($framework === null || $framework === '') {
            return $query;
        }

        $appId = app(Apps::class)->getId();

        return $query->whereHas('tools', function (Builder $q) use ($framework, $appId): void {
            $q->whereJsonContains('frameworks', $framework)
                ->where('is_active', 1)
                ->where('is_deleted', 0)
                ->whereIn('apps_id', [0, $appId]);
        });
    }
}
