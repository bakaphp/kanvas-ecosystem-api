<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Models;

use Baka\Casts\Json;
use Baka\Traits\HasCompositePrimaryKeyTrait;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Arr;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Models\BaseModel;
use Kanvas\ActionEngine\Tasks\Observers\TaskEngagementItemObserver;
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

    public function enableRelatedTasks(): bool
    {
        if ($this->status !== 'completed') {
            return false;
        }

        // Retrieve the items to enable from the config
        $itemsToEnable = Arr::get($this->item->task->config, 'task_item_to_enable', []);

        if (is_array($itemsToEnable) && ! empty($itemsToEnable)) {
            $affectedRows = $this->enableRelatedTaskItem($itemsToEnable);

            return $affectedRows > 0;
        }

        return false;
    }

    public function completeRelatedItems(): bool
    {
        if ($this->status !== 'completed') {
            return false;
        }

        // Retrieve the items to enable from the config
        $otherTaskItemsToComplete = Arr::get($this->item->config, 'complete_other_task_items', []);

        if (is_array($otherTaskItemsToComplete) && ! empty($otherTaskItemsToComplete)) {
            $affectedRows = $this->completeRelatedTaskItem($otherTaskItemsToComplete);

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

    /**
     * Enable related task engagement items based on the checklist configuration.
     *
     * This method processes a list of related task engagement items and verifies
     * if the checklist configuration criteria are met to change the "disabled" status.
     * Example configuration:
     * {
     *     "task_item_to_enable": {
     *         "67": [66, 65]
     *     }
     * }
     *
     * The configuration indicates that task item 67 should be enabled only if
     * all related task items (66 and 65 in this case) are completed.
     **/
    protected function enableRelatedTaskItem(array $relatedTask): int
    {
        $affectedRows = 0;

        foreach ($relatedTask as $checkListItem => $relatedCheckListItems) {
            // Fetch all related task items in bulk for validation
            $relatedItems = TaskEngagementItem::query()
                ->whereIn('task_list_item_id', $relatedCheckListItems)
                ->where('lead_id', $this->lead_id)
                ->where('companies_id', $this->company->getId())
                ->where('apps_id', $this->app->getId())
                ->get();

            // Check if all related items are completed
            $allCompleted = $relatedItems->count() === count($relatedCheckListItems) &&
                            $relatedItems->every(fn ($item) => $item->status === 'completed');

            if ($allCompleted) {
                // Enable the main task item if all related items are completed
                $taskEngagementItem = TaskEngagementItem::firstOrCreate([
                    'task_list_item_id' => $checkListItem,
                    'lead_id' => $this->lead_id,
                    'companies_id' => $this->company->getId(),
                    'apps_id' => $this->app->getId(),
                ], [
                    'users_id' => $this->user->getId(),
                    'status' => 'in_progress',
                ]);

                // Update status and config only if not already completed
                if ($taskEngagementItem->status !== 'completed') {
                    $taskEngagementItem->config = array_merge(
                        (array) $taskEngagementItem->config,
                        ['disabled' => false]
                    );
                    $taskEngagementItem->saveOrFail();
                    $affectedRows++;
                }
            }
        }

        return $affectedRows;
    }

    /**
     * Complete related task engagement items.
     *
     * This method processes a list of task engagement items identified by their IDs
     * and marks them as completed. If a task engagement item does not exist, it will
     * create one with the provided details.
     *
     * Example configuration:
     * {
     *     "complete_other_task_items": [2, 3]
     * }
     */
    protected function completeRelatedTaskItem(array $otherTaskItemsToComplete): int
    {
        $affectedRows = 0;

        foreach ($otherTaskItemsToComplete as $checkListItem) {
            // Fetch or create the task engagement item
            $taskEngagementItem = TaskEngagementItem::firstOrCreate([
                'task_list_item_id' => $checkListItem,
                'lead_id' => $this->lead_id,
                'companies_id' => $this->company->getId(),
                'apps_id' => $this->app->getId(),
            ], [
                'users_id' => $this->user->getId(),
                'status' => 'completed',
                'engagement_end_id' => $this->engagement_end_id,
            ]);

            // Increment affected rows only if a new record was created
            if ($taskEngagementItem->wasRecentlyCreated) {
                $affectedRows++;
            }
        }

        return $affectedRows;
    }
}
