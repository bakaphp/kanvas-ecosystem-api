<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Intelligence\Models\BaseModel;

/**
 * Class FollowUpTemplate
 * @property int $id
 * @property int $follow_up_days_id
 * @property string $communication_channel
 * @property string $name
 * @property string $template
 */

class FollowUpTemplate extends BaseModel
{
    use NoAppRelationshipTrait;
    protected $table = 'follow_up_templates';
    protected $guarded = [];

    public function followUpDay(): BelongsTo
    {
        return $this->belongsTo(FollowUpDay::class, 'follow_up_days_id');
    }
}
