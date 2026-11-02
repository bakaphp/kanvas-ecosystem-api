<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Scheduling\Actions;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Scheduling\Enums\ScheduledActionStatusEnum;
use Kanvas\NervousSystem\Scheduling\Models\ScheduledAction;

/**
 * Every wake still pointing at a task, cancelled. Called when the task closes and when it is snoozed
 * again, so a task never has two pending wakes and a closed task is never woken. Claimed rows count:
 * the running job re-checks the row before it acts and before it writes.
 */
class CancelTaskWakesAction
{
    /**
     * @param int|null $exceptWakeId the wake that is firing right now, when the cancel runs from inside
     *                               its own turn: it ends on its own and records itself as executed.
     */
    public function __construct(
        protected readonly Task $task,
        protected readonly ?int $exceptWakeId = null,
    ) {
    }

    public function execute(): int
    {
        $wakes = ScheduledAction::query()
            ->where('is_deleted', 0)
            ->where('source_entity_type', Task::class)
            ->where('source_entity_id', (string) $this->task->getId())
            ->whereIn('status', [
                ScheduledActionStatusEnum::PENDING->value,
                ScheduledActionStatusEnum::PAUSED->value,
                ScheduledActionStatusEnum::EXECUTING->value,
            ])
            ->when($this->exceptWakeId !== null, fn (Builder $query): Builder => $query->whereKeyNot($this->exceptWakeId))
            ->get();

        foreach ($wakes as $wake) {
            new CancelScheduledActionAction($wake)->execute();
        }

        return $wakes->count();
    }
}
