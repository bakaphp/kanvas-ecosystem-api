<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions;

use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Task;

/**
 * Sweeps tasks that have been in `in_progress` longer than the configured
 * threshold and emits one `plan.task.stalled` ledger event per stalled task.
 *
 * The action does NOT mutate task state — agents/humans decide what to do
 * with the stuckness signal (PR 5's Pub/Sub fans these out to interested
 * subscribers). This is the cheapest possible heuristic; LLM-judged
 * progress detection is a v2 enhancement.
 *
 * To avoid re-emitting on every cron tick, we tag the task as
 * "stuckness-emitted" by stamping a custom JSON marker into result. A
 * task whose `result.stuckness_emitted_at` was set within the same
 * stalled window is skipped.
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
