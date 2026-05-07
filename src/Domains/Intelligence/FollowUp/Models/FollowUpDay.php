<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\Models\BaseModel;

/**
 * Class FollowUpDay
 * @property int $id
 * @property int $follow_ups_id
 * @property int $pipeline_stages_id
 * @property string $name
 * @property int $time_value
 * @property string $time_unit
 * @property bool $calendar_day
 * @property int $move_to_stage_id
 */

class FollowUpDay extends BaseModel
{
    use NoAppRelationshipTrait;
    protected $table = 'follow_up_days';
    protected $guarded = [];

    public function followUp(): BelongsTo
    {
        return $this->belongsTo(FollowUp::class, 'follow_ups_id');
    }

    public function pipelineStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'pipeline_stages_id');
    }

    public function templates(): HasMany
    {
        return $this->hasMany(FollowUpTemplate::class, 'follow_up_days_id');
    }
}
