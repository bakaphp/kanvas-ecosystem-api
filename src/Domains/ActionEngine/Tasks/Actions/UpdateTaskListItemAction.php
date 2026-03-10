<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\ActionEngine\Tasks\DataTransferObject\TaskListItem as TaskListItemData;
use Kanvas\ActionEngine\Tasks\Models\TaskListItem;

class UpdateTaskListItemAction
{
    public function __construct(
        protected readonly TaskListItem $taskListItem,
        protected readonly TaskListItemData $data,
    ) {
    }

    public function execute(): TaskListItem
    {
        return DB::connection('action_engine')->transaction(function () {
            $this->taskListItem->name = $this->data->name;
            $this->taskListItem->companies_action_id = $this->data->companyAction->getId();
            $this->taskListItem->config = $this->data->config;
            $this->taskListItem->weight = $this->data->weight;

            if ($this->data->status !== null) {
                $this->taskListItem->status = $this->data->status;
            }

            $this->taskListItem->saveOrFail();

            return $this->taskListItem;
        });
    }
}
