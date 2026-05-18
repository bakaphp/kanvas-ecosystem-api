<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Intelligence\Models\BaseModel;
use Kanvas\KanvasModules\Models\KanvasModule;
use Override;

/**
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $agent_id
 * @property int $kanvas_modules_id
 * @property array|null $config
 * @property bool $is_active
 * @property bool $is_deleted
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class AgentKanvasModule extends BaseModel
{
    protected $table = 'agents_kanvas_modules';

    public $timestamps = true;

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'agent_id' => 'integer',
            'kanvas_modules_id' => 'integer',
            'config' => Json::class,
            'is_active' => 'boolean',
            'is_deleted' => 'boolean',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id', 'id');
    }

    // Cross-DB: KanvasModule lives on ecosystem, no FK constraint.
    public function module(): BelongsTo
    {
        return $this->belongsTo(KanvasModule::class, 'kanvas_modules_id', 'id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', 1)->where('is_deleted', 0);
    }
}
