<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Apps\Models\Apps;
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
 * @property int $agents_using_count
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
            'agents_using_count' => 'integer',
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

    public function scopeForApp(Builder $query, mixed $appsId = null): Builder
    {
        $id = $appsId instanceof Apps
            ? $appsId->getId()
            : (int) ($appsId ?? app(Apps::class)->getId());

        return $query->whereIn('apps_id', [0, $id]);
    }
}
