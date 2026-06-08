<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Models;

use Baka\Casts\Json;
use Dyrynda\Database\Support\CascadeSoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Intelligence\Models\BaseModel;

/**
 * Class FollowUp
 *
 * @deprecated v1 follow-up engine (stage.config.follow_up + agent-driven loop)
 *             replaced this. Slated for deletion after the deprecation window —
 *             see docs/intelligence/follow-up-deprecation-spec.md kill list.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $follow_up_type
 * @property int $pipelines_id
 * @property string $name
 * @property array|null $config
 */
class FollowUp extends BaseModel
{
    use CascadeSoftDeletes;

    protected $table = 'follow_ups';
    protected $guarded = [];

    protected $cascadeDeletes = ['days'];

    protected $casts = [
        'config' => Json::class,
    ];

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class, 'pipelines_id');
    }

    public function days(): HasMany
    {
        return $this->hasMany(FollowUpDay::class, 'follow_ups_id');
    }
}
