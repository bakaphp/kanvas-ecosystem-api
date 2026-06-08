<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Listeners;

use Kanvas\NervousSystem\Plan\Enums\PlanChangeTypeEnum;
use Kanvas\NervousSystem\Plan\Events\PlanBroadcast;
use Kanvas\NervousSystem\Plan\Notifications\PlanProgressNotification;

/**
 * When the agent moves work on the board (a sync-originated status change), notify the plan's human
 * owner — the person who created/assigned it — that their agent made progress.
 *
 * Gated to `fromSync` (agent-driven) changes only; a human's own UI edits don't notify themselves.
 * Self-notification is skipped too: board-created plans are owned by the agent's user, so there's no
 * human to tell.
 */
class NotifyPlanCreatorOfAgentProgressListener
{
    public function handle(PlanBroadcast $event): void
    {
        if (! $event->fromSync) {
            return;
        }

        if (! in_array($event->changeType, [PlanChangeTypeEnum::TASK_STATUS_CHANGED, PlanChangeTypeEnum::UPDATED], true)) {
            return;
        }

        $plan = $event->plan;
        $recipient = $plan->user;

        if ($recipient === null || $recipient->getId() === $plan->agent?->user?->getId()) {
            return;
        }

        $agentName = $plan->agent?->name ?? 'The agent';
        $task = $event->task;

        if ($task !== null) {
            $title = 'Task updated';
            $message = sprintf('%s moved "%s" to %s.', $agentName, $task->title, $this->label($task->status));
        } else {
            $title = 'Plan updated';
            $message = sprintf('%s updated "%s" to %s.', $agentName, $plan->title, $this->label($plan->status));
        }

        $recipient->notify(new PlanProgressNotification(
            $plan,
            $title,
            $message,
            [
                'plan_id' => $plan->id,
                'plan_uuid' => $plan->uuid,
                'task_id' => $task?->id,
                'change_type' => $event->changeType->value,
                'status' => $task?->status ?? $plan->status,
            ],
        ));
    }

    private function label(string $status): string
    {
        return ucfirst(str_replace('_', ' ', $status));
    }
}
