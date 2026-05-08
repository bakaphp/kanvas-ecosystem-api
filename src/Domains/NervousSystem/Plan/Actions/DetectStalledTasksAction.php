<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions;

use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Task;

/**
 * Idempotent across cron ticks: each emission stamps `result.stuckness_emitted_at`
 * on the task, and tasks already stamped are skipped on subsequent runs.
 */
class DetectStalledTasksAction
{
    public function __construct(
        public readonly int $stalledAfterMinutes = 30,
    ) {
    }

    /**
     * @return array{checked: int, emitted: int}
     */
    public function execute(): array
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, Task> $stalled */
        $stalled = Task::query()
            ->stalled($this->stalledAfterMinutes)
            ->with('plan')
            ->get();

        $emitted = 0;
        $checked = $stalled->count();

        foreach ($stalled as $task) {
            /** @var array<string, mixed> $existingResult */
            $existingResult = is_array($task->result) ? $task->result : [];

            if (isset($existingResult['stuckness_emitted_at'])) {
                continue;
            }

            $plan = $task->plan;
            $minutesElapsed = $task->started_at !== null
                ? (int) $task->started_at->diffInMinutes(now())
                : null;

            $task->emitLedgerEvent(
                eventType: 'plan.task.stalled',
                status: EventStatusEnum::ERROR,
                payload: [
                    'plan_id' => $task->plan_id,
                    'sequence' => $task->sequence,
                    'title' => $task->title,
                    'minutes_in_progress' => $minutesElapsed,
                    'threshold_minutes' => $this->stalledAfterMinutes,
                    'agent_id' => $plan?->agent_id,
                    'users_id' => $plan?->users_id,
                ],
                actorType: 'System',
                actorId: null,
            );

            $existingResult['stuckness_emitted_at'] = now()->toIso8601String();
            $task->result = $existingResult;
            $task->saveOrFail();

            $emitted++;
        }

        return [
            'checked' => $checked,
            'emitted' => $emitted,
        ];
    }
}
