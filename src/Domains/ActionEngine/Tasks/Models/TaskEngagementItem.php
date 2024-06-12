<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Models\BaseModel;
use Kanvas\Guild\Leads\Models\Lead;

/**
 * Class TaskEngagementItem.
 *
 * @property int $task_list_item_id
 * @property int $lead_id
 * @property int $companies_id
 * @property int $apps_id
 * @property string $status
 * @property int $engagement_start_id
 * @property int $engagement_end_id
 * @property string $config
 */
class TaskEngagementItem extends BaseModel
{
    protected $table = 'company_task_engagement_items';
    protected $guarded = [];

    protected $casts = [
        'config' => Json::class,
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(TaskListItem::class, 'task_list_item_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    public function engagementStart(): HasOne
    {
        return $this->hasOne(Engagement::class, 'id', 'engagement_start_id');
    }

    public function engagementEnd(): HasOne
    {
        return $this->hasOne(Engagement::class, 'id', 'engagement_end_id');
    }
}
