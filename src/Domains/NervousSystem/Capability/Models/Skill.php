<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\NervousSystem\Ledger\Traits\EmitsLedgerEventsForEntity;
use Kanvas\NervousSystem\Models\BaseModel;
use Override;

/**
 * Skill catalog entry. apps_id=0 means a global skill available to all apps.
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property string $name
 * @property string|null $description
 * @property string $skill_type
 * @property string|null $handler
 * @property array|null $definition
 * @property array $frameworks
 * @property string $version
 * @property bool $is_active
 * @property bool $is_deleted
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Skill extends BaseModel
{
    use EmitsLedgerEventsForEntity;
    use UuidTrait;

    protected $table = 'nervous_system_skills';

    public $timestamps = true;

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'apps_id' => 'integer',
            'definition' => Json::class,
            'frameworks' => Json::class,
            'is_active' => 'boolean',
            'is_deleted' => 'boolean',
        ];
    }

    public function agentGrants(): HasMany
    {
        return $this->hasMany(AgentSkill::class, 'skill_id', 'id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', 1)->where('is_deleted', 0);
    }

    public function scopeForFramework(Builder $query, string $framework): Builder
    {
        return $query->whereJsonContains('frameworks', $framework);
    }

    /**
     * Skill catalog rows have apps_id but not companies_id (skills are
     * app-wide, not per-company). Override fromApp to also include
     * apps_id=0 (global skills available across the platform).
     */
    public function scopeForApp(Builder $query, int $appsId): Builder
    {
        return $query->whereIn('apps_id', [0, $appsId]);
    }
}
