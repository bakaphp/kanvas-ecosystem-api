<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Models;

use Baka\Casts\Json;
use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Models\BaseModel;
use Kanvas\Guild\Leads\Models\Lead;

/**
 * Class Tasks.
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
    //use UuidTrait;
    use NoAppRelationshipTrait;

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

    /**
     * Given a list of files, complete the task list items that are related to the files.
     * [{"privacy-disclosure.pdf":"privacy-disclosure.pdf"}]
     */
    public function completeByRelatedDocumentItems(array $files, Lead $lead, ?Engagement $engagement = null): bool
    {
        $totalAffected = 0;

        foreach ($files as $file) {
            $companyTaskItem = self::where(function ($query) use ($file) {
                $query->whereRaw('JSON_UNQUOTE(JSON_EXTRACT(config, "$.file_name")) = ?', [$file])
                    ->orWhereRaw('JSON_CONTAINS(JSON_EXTRACT(config, "$.file_name"), JSON_QUOTE(?))', [$file]);
            })
            ->where('is_deleted', 0)
            ->where('companies_action_id', $this->companies_action_id)
            ->where('task_list_id', $this->task_list_id)
            ->first();

            if ($companyTaskItem) {
                $taskEngagementItem = TaskEngagementItem::fromCompany($this->company())
                    ->fromApp($this->task->app)
                    ->where('task_list_item_id', $companyTaskItem->getId())
                    ->where('lead_id', $lead->getId())
                    ->first();

                if (! $taskEngagementItem) {
                    $taskEngagementItem = new TaskEngagementItem();
                    $taskEngagementItem->task_list_item_id = $companyTaskItem->getId();
                    $taskEngagementItem->lead_id = $lead->getId();
                    $taskEngagementItem->companies_id = $this->companies_id;
                    $taskEngagementItem->apps_id = $this->apps_id;
                    $taskEngagementItem->users_id = $this->users_id;
                    $taskEngagementItem->engagement_end_id = $engagement ? $engagement->getId() : null;
                }

                $taskEngagementItem->status = 'completed';
                $taskEngagementItem->saveOrFail();
                $totalAffected++;
            }
        }

        return $totalAffected > 0;
    }
}
