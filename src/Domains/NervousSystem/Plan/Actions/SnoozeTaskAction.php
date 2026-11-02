<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Scheduling\Actions\CancelTaskWakesAction;
use Kanvas\NervousSystem\Scheduling\Actions\CreateScheduledActionAction;
use Kanvas\NervousSystem\Scheduling\DataTransferObject\ScheduledAction as ScheduledActionData;
use Kanvas\NervousSystem\Scheduling\Enums\ScheduledActionTypeEnum;
use Kanvas\NervousSystem\Scheduling\Models\ScheduledAction;
use Kanvas\NervousSystem\Scheduling\Services\ScheduledActionTimezoneResolver;

/**
 * Park a task until a date and book the wake that reopens it, as one write. Asking the model to set
 * the status and then schedule the wake is how a wake gets forgotten; asking it to cancel the old one
 * first is how a task ends up with two. The wake points at the task (`source_entity`), which is what
 * lets closing the task cancel it and lets the heartbeat tell a planned wait from a lost wake.
 */
class SnoozeTaskAction
{
    public function __construct(
        protected readonly Task $task,
        protected readonly Agent $agent,
        protected readonly Carbon $until,
        protected readonly string $reason,
        protected readonly ?Session $session = null,
    ) {
    }

    public function execute(): ScheduledAction
    {
        return DB::connection('intelligence')->transaction(function (): ScheduledAction {
            new CancelTaskWakesAction($this->task)->execute();

            $plan = $this->task->plan;
            $recipient = $plan?->user ?? $this->agent->user;
            $timezone = new ScheduledActionTimezoneResolver()->resolve($this->agent->company, $recipient);

            $wake = new CreateScheduledActionAction(
                new ScheduledActionData(
                    app: $this->agent->app,
                    company: $this->agent->company,
                    user: $recipient,
                    type: ScheduledActionTypeEnum::AGENT_TASK,
                    timezone: $timezone,
                    runAt: $this->until,
                    agent: $this->agent,
                    instruction: $this->instruction(),
                    channel: $this->session?->channel?->slug,
                    sessionUuid: $this->session?->uuid,
                    sourceEntityType: Task::class,
                    sourceEntityId: (string) $this->task->getId(),
                ),
            )->execute();

            new UpdateTaskStatusAction(
                task: $this->task,
                newStatus: TaskStatusEnum::WAITING,
                blockedReason: $this->reason,
                wakeAt: $this->until,
            )->execute();

            return $wake;
        });
    }

    private function instruction(): string
    {
        $where = $this->task->waiting_on_channel_id !== null
            ? sprintf('Re-read channel %d (read_channel_window) first', $this->task->waiting_on_channel_id)
            : 'Re-read the conversation the ask went out in first';
        $who = $this->task->waitingOn?->email;

        return sprintf(
            "Task #%d \"%s\" is due again. You parked it because: %s\n%s%s. If the answer is already there, "
            . 'record it on the task and mark the task done. If not, ask again, and snooze the task to the '
            . 'date you are given. Do not open a new task for this.',
            $this->task->getId(),
            $this->task->title,
            $this->reason,
            $who !== null ? 'You are waiting on ' . $who . '. ' : '',
            $where,
        );
    }
}
