<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Observers;

use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Task;

class TaskObserver
{
    /**
     * Every plan is born in TODO; the first task to start is what moves it. A writer that saves a task
     * quietly bypasses this and must call Plan::startWork() itself, as RunTaskWorkerJob does.
     */
    public function saved(Task $task): void
    {
        $started = $task->status === TaskStatusEnum::IN_PROGRESS->value
            && ($task->wasRecentlyCreated || $task->wasChanged('status'));

        if ($started) {
            $task->plan?->startWork();
        }
    }
}
