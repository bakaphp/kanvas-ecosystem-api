<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Baka\Support\Str;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Plan\Actions\OpenFollowUpAction;
use Kanvas\NervousSystem\Plan\Actions\RecordTaskAskAction;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Scheduling\Services\ScheduledActionTimezoneResolver;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * For the tools that carry an ask to a teammate: when the ask belongs to a task, record on the task
 * who now owes the answer and where it was asked, so the reply is matched to the task and the wake
 * knows which thread to re-read.
 */
trait LinksAskToTask
{
    /**
     * @return array<string, mixed> keys to merge into the tool's result: `task_id` and, when the
     *                              agent already waits on this person elsewhere, `also_waiting_on_this_person`
     *                              with the guidance to send one message instead of two
     */
    protected function linkAskToTask(?int $taskId, Agent $agent, Users $recipient, ?int $channelId = null): array
    {
        if ($taskId === null) {
            return [];
        }

        $task = $this->askTask($taskId, $agent);

        return $task === null
            ? $this->taskNotFound($taskId)
            : $this->recordAskOnTask($task, $recipient, $channelId);
    }

    /**
     * What a send tool records once its message is out: the link to the task it was asked for, or —
     * when there is no task yet but something is being waited on — a follow-up opened for the person
     * in the conversation. One call, so the agent cannot send the ask and forget the half that makes
     * the reply come back.
     *
     * @return array<string, mixed>
     */
    protected function recordAsk(
        ?int $taskId,
        ?string $waitingFor,
        ?string $until,
        Agent $agent,
        Users $recipient,
        ?Users $requester,
        ?Session $session,
        ?int $channelId,
    ): array {
        $waitingFor = Str::trimToNull($waitingFor);

        if ($taskId !== null || $waitingFor === null) {
            return $this->linkAskToTask(
                $taskId,
                $agent,
                $recipient,
                $channelId,
            );
        }

        if ($requester === null) {
            return ['waiting_for_warning' => 'No follow-up was opened: this turn has no person to open it for. '
                . 'Open the task another way and snooze_task it.'];
        }

        return $this->openFollowUp(
            $agent,
            $recipient,
            $waitingFor,
            $until,
            $requester,
            $session,
            $channelId,
        );
    }

    /**
     * Open the wait: a follow-up plan and task, parked on the recipient until the date they gave,
     * owned by the person it is for. Never throws past a delivered message — a wake that cannot be
     * booked comes back as a warning.
     *
     * @return array<string, mixed>
     */
    protected function openFollowUp(
        Agent $agent,
        ?Users $recipient,
        string $waitingFor,
        ?string $until,
        Users $requestedBy,
        ?Session $session,
        ?int $channelId,
    ): array {
        $resolver = new ScheduledActionTimezoneResolver();
        $timezone = $resolver->resolve($agent->company, $requestedBy);

        try {
            $wakeAt = $resolver->wakeAt($until, $timezone);
        } catch (Throwable) {
            return ['waiting_for_warning' => 'No follow-up was opened: I could not read the date in `until`. Use '
                . '"YYYY-MM-DD HH:MM", or leave it empty for tomorrow at 09:00.'];
        }

        try {
            $task = new OpenFollowUpAction(
                agent: $agent,
                recipient: $recipient,
                waitingFor: $waitingFor,
                until: $wakeAt,
                requestedBy: $requestedBy,
                session: $session,
                channelId: $channelId,
            )->execute();
        } catch (ValidationException $e) {
            return ['waiting_for_warning' => 'No follow-up was opened: ' . $e->getMessage()];
        }

        return [
            'task_id' => $task->getId(),
            'follow_up_plan_id' => $task->plan_id,
            'wake_at' => $wakeAt->copy()->setTimezone($timezone)->format('Y-m-d H:i'),
            'timezone' => $timezone,
            'guidance' => sprintf(
                'Follow-up task #%d is open and parked until then; you will be woken to chase it%s. '
                . 'Do not snooze it again now and do not post a reminder yourself.',
                $task->getId(),
                $recipient === null
                    ? ''
                    : sprintf(', and %s\'s reply will reach you with the task in hand', trim((string) $recipient->firstname) ?: $recipient->email),
            ),
        ];
    }

    protected function askTask(?int $taskId, Agent $agent): ?Task
    {
        if ($taskId === null) {
            return null;
        }

        /** @var Task|null $task */
        $task = Task::query()
            ->where('id', $taskId)
            ->fromApp($agent->app)
            ->fromCompany($agent->company)
            ->notDeleted()
            ->first();

        return $task;
    }

    /**
     * @return array<string, string>
     */
    protected function taskNotFound(int $taskId): array
    {
        return ['task_id_warning' => "Task {$taskId} was not found, so this ask was not recorded on it."];
    }

    /**
     * @return array<string, mixed>
     */
    protected function recordAskOnTask(Task $task, Users $recipient, ?int $channelId = null): array
    {
        $others = new RecordTaskAskAction($task, $recipient, $channelId)->execute();

        if ($others->isEmpty()) {
            return ['task_id' => $task->getId()];
        }

        return [
            'task_id' => $task->getId(),
            'also_waiting_on_this_person' => $others
                ->map(fn (Task $other): string => sprintf('#%d %s', $other->getId(), Str::limit((string) $other->title, 80)))
                ->all(),
            'guidance' => 'You already have other open asks to this person. Next time, send ONE message that lists '
                . 'everything you need from them rather than one per task; if two asks are for the same thing, '
                . 'keep one task and close the other.',
        ];
    }
}
