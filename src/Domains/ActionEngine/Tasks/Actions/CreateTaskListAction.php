<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\ActionEngine\Tasks\DataTransferObject\TaskList as TaskListData;
use Kanvas\ActionEngine\Tasks\Models\TaskList;

class CreateTaskListAction
{
    public function __construct(
        protected readonly TaskListData $data,
    ) {
    }

    public function execute(): TaskList
    {
        return DB::connection('action_engine')->transaction(function () {
            $taskList = new TaskList();
            $taskList->apps_id = $this->data->app->getId();
            $taskList->companies_id = $this->data->company->getId();
            $taskList->users_id = $this->data->user->getId();
            $taskList->name = $this->data->name;
            $taskList->config = $this->data->config;
            $taskList->saveOrFail();

            return $taskList;
        });
    }
}
