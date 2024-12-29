<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\CheckList\Models;

use Baka\Casts\Json;
use Baka\Traits\HasCompositePrimaryKeyTrait;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Arr;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Models\BaseModel;
use Kanvas\ActionEngine\CheckList\Observers\TaskEngagementItemObserver;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Traits\CanUseWorkflow;

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
#[ObservedBy([TaskEngagementItemObserver::class])]
class TaskEngagementItem extends BaseModel
{
    use HasCompositePrimaryKeyTrait;
    use CanUseWorkflow;

    protected $table = 'company_task_engagement_items';
    protected $guarded = [];

    protected $casts = [
        'config' => Json::class,
    ];

    protected $primaryKey = ['task_list_item_id','lead_id'];

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

    public function disableRelatedItems(): bool
    {
        if ($this->status !== 'no_applicable') {
            return false;
        }

        // Retrieve the items to disable from the config
        $itemsToDisable = Arr::get($this->item->config, 'other_items_to_disable', []);

        if (is_array($itemsToDisable) && ! empty($itemsToDisable)) {
            $affectedRows = $this->disableItems($itemsToDisable);

            return $affectedRows > 0;
        }

        return false;
    }

    protected function disableItems(array $itemsToDisable): int
    {
        $affectedRows = 0;

        foreach ($itemsToDisable as $itemId) {
            // Use firstOrNew to either find the item or create a new instance
            $taskEngagementItem = TaskEngagementItem::firstOrNew([
                'task_list_item_id' => $itemId,
                'lead_id' => $this->lead_id,
                'companies_id' => $this->company->getId(),
                'apps_id' => $this->app->getId(),
            ], [
                'users_id' => $this->user->getId(),
            ]);

            // Only update the status if it's not completed
            if ($taskEngagementItem->status !== 'completed') {
                $taskEngagementItem->status = 'no_applicable';
                $taskEngagementItem->saveOrFail();
                $affectedRows++; // Increment only if something was changed
            }
        }

        return $affectedRows;
    }
}
