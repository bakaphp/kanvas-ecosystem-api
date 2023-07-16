<?php

declare(strict_types=1);

namespace Kanvas\Guild\Pipelines\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\SlugTrait;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Guild\Models\BaseModel;

/**
 * Class Pipeline.
 *
 * @property int $id
 * @property int $companies_id
 * @property int $users_id
 * @property int $system_modules_id
 * @property string $name
 * @property string $slug
 * @property int $weight
 * @property int $is_default
 */
class Pipeline extends BaseModel
{
    use NoAppRelationshipTrait;
    use SlugTrait;

    protected $table = 'pipelines';
    protected $guarded = [];

    public function stages(): HasMany
    {
        return $this->hasMany(PipelineStage::class, 'pipelines_id', 'id');
    }
}
