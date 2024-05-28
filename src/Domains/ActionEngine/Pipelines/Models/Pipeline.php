<?php

declare(strict_types=1);

namespace Domains\ActionEngine\Pipelines\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\ActionEngine\Models\BaseModel;

/**
 * Class Pipeline.
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int $users_id
 * @property string $slug
 * @property string $name
 * @property int $weight
 */
class Pipeline extends BaseModel
{
    use UuidTrait;

    protected $table = 'engagements';
    protected $guarded = [];

    public function stages(): HasMany
    {
        return $this->hasMany(PipelineStage::class, 'pipelines_id', 'id');
    }
}
