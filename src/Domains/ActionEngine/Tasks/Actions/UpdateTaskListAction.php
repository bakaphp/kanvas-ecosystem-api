<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\ActionEngine\Tasks\DataTransferObject\TaskList as TaskListData;
use Kanvas\ActionEngine\Tasks\Models\TaskList;

class UpdateTaskListAction
{
    public function __construct(
        protected readonly TaskList $taskList,
        protected readonly TaskListData $data,
    ) {
    }

    public function execute(): TaskList
    {
        return DB::connection('action_engine')->transaction(function () {
            $this->taskList->name = $this->data->name;
            $this->taskList->config = $this->data->config;
            $this->taskList->saveOrFail();

            return $this->taskList;
        });
    }
}
