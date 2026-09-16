<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Observers;

use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Task;

class TaskObserver
{
    /**
     * Every plan is born in TODO; the first task to start is what moves it. Sitting on the model means
     * no writer of task status — agent tools, kanban sync, the PiDev poller — can forget to.
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
