<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Pipelines\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\ActionEngine\Models\BaseModel;

/**
 * Class PipelineStageMessage
 *
 * @property int $id
 * @property int $pipelines_stages_id
 * @property string $message
 * @property string $message_notification
 */
class PipelineStageMessage extends BaseModel
{
    use NoAppRelationshipTrait;

    protected $table = 'pipelines_stages_messages';
    protected $guarded = [];

    public function pipelineStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'pipelines_stages_id', 'id');
    }
}
