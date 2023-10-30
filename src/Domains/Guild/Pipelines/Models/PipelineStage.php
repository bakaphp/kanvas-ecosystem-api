<?php

declare(strict_types=1);

namespace Kanvas\Guild\Pipelines\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Models\BaseModel;

/**
 * Class PipelineStage.
 *
 * @property int $id
 * @property int $pipelines_id
 * @property string $name
 * @property int $has_rotting_days
 * @property int $rotting_days
 * @property int $weight
 */
class PipelineStage extends BaseModel
{
    use NoAppRelationshipTrait;

    protected $table = 'pipelines_stages';
    protected $guarded = [];

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class, 'pipelines_id', 'id');
    }

    public function leads(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'pipeline_stage_id', 'id');
    }
}
