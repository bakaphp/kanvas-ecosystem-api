<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Models\BaseModel;

/**
 * Class Task Items or Checklist Items.
 *
 * @property int $id
 * @property int $task_list_id
 * @property int $companies_action_id
 * @property string $name
 * @property string $config
 * @property string $status
 * @property float $weight
 */
class TaskListItem extends BaseModel
{
    use UuidTrait;

    protected $table = 'company_task_list_items';
    protected $guarded = [];

    protected $casts = [
        'config' => Json::class,
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(TaskList::class, 'task_list_id');
    }

    public function companyAction(): BelongsTo
    {
        return $this->belongsTo(CompanyAction::class, 'companies_action_id');
    }

    /**
      * temp relationship to engagement will only work on LeadTaskEngagementItem
      */
    public function engagementStart(): HasOne
    {
        return $this->hasOne(Engagement::class, 'id', 'engagement_start_id');
    }

    /**
     * temp relationship to engagement will only work on LeadTaskEngagementItem
     */
    public function engagementEnd(): HasOne
    {
        return $this->hasOne(Engagement::class, 'id', 'engagement_end_id');
    }

    public function getConfig(): array
    {
        return (array) $this->config;
    }
}
