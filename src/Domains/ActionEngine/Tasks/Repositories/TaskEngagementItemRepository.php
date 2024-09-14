<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\ActionEngine\Tasks\Models\TaskListItem;
use Kanvas\Guild\Leads\Models\Lead;

class TaskEngagementItemRepository
{
    public static function getLeadsTaskItems(Lead $lead, ?int $taskListId = null): Builder
    {
        return TaskListItem::leftJoin('company_task_engagement_items', function ($join) use ($lead) {
            $join->on('company_task_list_items.id', '=', 'company_task_engagement_items.task_list_item_id')
                 ->where('company_task_engagement_items.lead_id', '=', $lead->getId());
        })
        ->leftJoin('company_task_list', 'company_task_list.id', '=', 'company_task_list_items.task_list_id')
        ->where('company_task_list.companies_id', '=', $lead->companies_id)
        ->when($taskListId, function ($query, $taskListId) {
            return $query->where('company_task_list_items.task_list_id', $taskListId);
        })
        ->select(
            'company_task_list_items.*',
            'company_task_engagement_items.lead_id',
            'company_task_engagement_items.status',
            'company_task_engagement_items.engagement_start_id',
            'company_task_engagement_items.engagement_end_id',
            'company_task_engagement_items.created_at',
            'company_task_engagement_items.updated_at'
        );
    }
}
