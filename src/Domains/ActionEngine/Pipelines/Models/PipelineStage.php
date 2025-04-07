<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Pipelines\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\ActionEngine\Models\BaseModel;

/**
 * Class PipelineStage
 *
 * @property int $id
 * @property string $uuid
 * @property int $pipelines_id
 * @property int $companies_id
 * @property int $users_id
 * @property string $name
 * @property string $slug
 * @property int $has_rotting_days
 * @property int $rotting_days
 * @property int $weight
 */
class PipelineStage extends BaseModel
{
    use UuidTrait;

    protected $table = 'pipelines_stages';
    protected $guarded = [];

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class, 'pipelines_id', 'id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(PipelineStageMessage::class, 'pipelines_stages_id', 'id');
    }
}
