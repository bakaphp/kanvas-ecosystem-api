<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\ActionEngine\Tasks\DataTransferObject\TaskListItem as TaskListItemData;
use Kanvas\ActionEngine\Tasks\Models\TaskListItem;

class CreateTaskListItemAction
{
    public function __construct(
        protected readonly TaskListItemData $data,
    ) {
    }

    public function execute(): TaskListItem
    {
        return DB::connection('action_engine')->transaction(function () {
            $taskListItem = new TaskListItem();
            $taskListItem->task_list_id = $this->data->taskList->getId();
            $taskListItem->name = $this->data->name;
            $taskListItem->companies_action_id = $this->data->companyAction->getId();
            $taskListItem->status = $this->data->status ?? 'pending';
            $taskListItem->config = $this->data->config;
            $taskListItem->weight = $this->data->weight;
            $taskListItem->saveOrFail();

            return $taskListItem;
        });
    }
}
