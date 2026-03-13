<?php

declare(strict_types=1);

namespace Kanvas\Guild\Pipelines\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Guild\Pipelines\Observers\PipelineStageObserver;
use Kanvas\Intelligence\FollowUp\Models\FollowUpDay;
use Override;

/**
 * Class PipelineStage.
 *
 * @property int $id
 * @property int $pipelines_id
 * @property string $name
 * @property int $has_rotting_days
 * @property int $rotting_days
 * @property string|null $config
 * @property int $weight
 */
#[ObservedBy(PipelineStageObserver::class)]
class PipelineStage extends BaseModel
{
    use NoAppRelationshipTrait;

    protected $table = 'pipelines_stages';
    protected $guarded = [];

    #[Override]
    public function casts(): array
    {
        return [
            'config' => 'array',
        ];
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class, 'pipelines_id', 'id');
    }

    public function leads(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'pipeline_stage_id', 'id');
    }

    public function followUpDays(): HasMany
    {
        return $this->hasMany(FollowUpDay::class, 'pipeline_stages_id', 'id');
    }
}
